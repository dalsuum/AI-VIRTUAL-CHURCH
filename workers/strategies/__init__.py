"""
Music-source strategy.

A service can be scored four ways depending on the user's `music_source`:

  - HymnStrategy        -> serves a pre-rendered public-domain hymn from a local
                           library (no AI, no credit, no provider call). The default.
  - HymnYouTubeStrategy -> selects a mood-appropriate hymn from the local catalog,
                           then finds and embeds it from the HymnSite YouTube channel.
  - SunoStrategy        -> generates original worship music from a text prompt (AI).
  - YouTubeStrategy     -> searches YouTube for an existing modern worship track and
                           returns an embeddable video id (no generation, no file storage).

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

    `language` is the service language ('en' | 'my' | 'td'). Burmese and Tedim
    services route all hymn-flavoured sources — including 'youtube' — to their
    own strategy classes (MyanmarHymnStrategy / TedimHymnStrategy).  Those
    libraries already contain hundreds of YouTube-embed hymns, so routing through
    YouTubeStrategy's live API search is unnecessary and unreliable for those
    languages.  English uses YouTubeStrategy for 'youtube' as before."""
    from .hymn_my_strategy import MyanmarHymnStrategy
    from .hymn_strategy import HymnStrategy
    from .tedim_hymn_strategy import TedimHymnStrategy
    from .hymn_youtube_strategy import HymnYouTubeStrategy
    from .suno_strategy import SunoStrategy
    from .youtube_strategy import YouTubeStrategy

    if language == "my" and music_source in ("hymn_sung", "hymn", "hymn_youtube", "youtube"):
        return MyanmarHymnStrategy()  # mood-matched Burmese hymn, sung, lyrics on screen
    if language == "td" and music_source in ("hymn_sung", "hymn", "hymn_youtube", "youtube"):
        return TedimHymnStrategy()  # Tedim hymn: YouTube embed or cached render
    if music_source == "suno":
        return SunoStrategy()
    if music_source == "musicgen":
        from .musicgen_strategy import MusicGenStrategy
        return MusicGenStrategy()
    if music_source == "youtube":
        return YouTubeStrategy(language=language)
    if music_source == "hymn_youtube":
        return HymnYouTubeStrategy()  # mood-matched hymn from HymnSite YouTube channel
    if music_source == "hymn":
        return HymnStrategy(sung=False)  # instrumental render + on-screen lyrics
    return HymnStrategy(sung=True)  # default `hymn_sung`: public-domain sung recording
