"""
Music-source strategy.

A service can be scored depending on the user's `music_source`:

  hymn_sung      → SungHymnStrategy      local vintage/vocal MP3 (en/my/td), lyrics on screen
  hymn           → InstrumentalHymnStrategy  local MIDI render (en/my/td), lyrics on screen
  hymn_youtube   → HymnYouTubeStrategy   mood-matched hymn found on YouTube (en/my/td)
  suno           → SunoStrategy          AI-generated music via Suno API
  musicgen       → MusicGenStrategy      AI-generated music via local MusicGen (CPU-default)
  local_ai       → LocalAiStrategy       GPU-preferred MusicGen; same model, CUDA when available
  youtube        → YouTubeStrategy       modern worship track searched on YouTube (en/my/td)

All return a normalized `MusicResult` so the orchestrator and the Laravel webhook
treat them identically. Add a new source by implementing `MusicStrategy`.
"""

from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass


@dataclass
class MusicResult:
    # asset_type maps directly to the Laravel service_assets.asset_type enum.
    asset_type: str            # "audio" (Suno file) | "youtube" (embedded clip)
    storage_key: str | None = None   # object-storage key for generated audio
    provider_ref: str | None = None  # YouTube video id, or Suno job id
    title: str | None = None
    lyrics: str | None = None  # hymn verses or generated Suno custom-mode lyrics


class MusicStrategy(ABC):
    """Common contract for every music source."""

    @abstractmethod
    def fetch(self, *, mood: str, prompt: str, query: str) -> MusicResult:
        """
        Produce one worship track for the given service.

        mood   - extracted emotional theme (e.g. "grieving", "joyful")
        prompt - rich text prompt for AI generation (Suno). For AI-composed
                 music, callers may append "Lyrics:\n..." so the same words can
                 be sent to Suno customMode and shown on screen.
        query  - short search string for catalog lookup (YouTube)
        """
        raise NotImplementedError


def get_strategy(music_source: str, language: str = "en") -> MusicStrategy:
    """Factory: resolve the user's preference string to a concrete strategy.

    `language` is the service language ('en' | 'my' | 'td'). Every source now
    routes through a unified language-aware strategy — no per-language overrides.
    """
    from .hymn_youtube_strategy import HymnYouTubeStrategy
    from .instrumental_hymn_strategy import InstrumentalHymnStrategy
    from .sung_hymn_strategy import SungHymnStrategy
    from .suno_strategy import SunoStrategy
    from .youtube_strategy import YouTubeStrategy

    if music_source == "suno":
        return SunoStrategy()
    if music_source == "local_ai":
        from .local_ai_strategy import LocalAiStrategy
        return LocalAiStrategy()
    if music_source == "musicgen":
        from .musicgen_strategy import MusicGenStrategy
        return MusicGenStrategy()
    if music_source == "youtube":
        return YouTubeStrategy(language=language)
    if music_source == "hymn_youtube":
        return HymnYouTubeStrategy(language=language)
    if music_source == "hymn":
        return InstrumentalHymnStrategy(language=language)
    # Default covers "hymn_sung" and any unrecognised value
    return SungHymnStrategy(language=language)
