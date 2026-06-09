"""
Dual music-source strategy.

A service can be scored two ways depending on the user's `music_source` preference:

  - SunoStrategy    -> generates original worship music from a text prompt (AI).
  - YouTubeStrategy -> searches YouTube for an existing worship track and returns
                       an embeddable video id (no generation, no file storage).

Both return a normalized `MusicResult` so the orchestrator and the Laravel webhook
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


class MusicStrategy(ABC):
    """Common contract for every music source."""

    @abstractmethod
    def fetch(self, *, mood: str, prompt: str, query: str) -> MusicResult:
        """
        Produce one worship track for the given service.

        mood   - extracted emotional theme (e.g. "grieving", "joyful")
        prompt - rich text prompt for AI generation (Suno)
        query  - short search string for catalog lookup (YouTube)
        """
        raise NotImplementedError


def get_strategy(music_source: str) -> MusicStrategy:
    """Factory: resolve the user's preference string to a concrete strategy."""
    from .suno_strategy import SunoStrategy
    from .youtube_strategy import YouTubeStrategy

    if music_source == "youtube":
        return YouTubeStrategy()
    return SunoStrategy()  # default
