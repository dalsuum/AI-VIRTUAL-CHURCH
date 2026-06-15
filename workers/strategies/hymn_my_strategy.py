"""Myanmar hymn strategy — a Burmese hymn, sung, with the words on screen.

NOTE: The active router (strategies/__init__.py) routes `hymn_sung` and `hymn`
through the unified SungHymnStrategy / InstrumentalHymnStrategy instead.
This class is kept for reference and legacy compatibility only.

Resolution order (updated to prefer locally-downloaded files):
  1. hymns_my/<slug>.sung.mp3  — locally downloaded sung recording (preferred)
  2. hymns_my/<slug>.mp3       — cached Suno render from a previous generation
  3. Suno API                  — generate on-the-fly and cache (fallback only)
"""

from __future__ import annotations

import os

import storage
from hymns_my import select

from . import MusicResult, MusicStrategy
from ._suno_custom import sing

CACHE_KEY = "hymns_my/{slug}.mp3"

_STYLE = os.getenv(
    "SUNO_MY_STYLE",
    "Traditional Christian hymn, reverent congregational choir with piano and organ, "
    "sung in the Burmese (Myanmar) language",
)



class MyanmarHymnStrategy(MusicStrategy):
    """Mood-matched Burmese hymn: local file preferred, cached Suno render as fallback."""

    def fetch(self, *, mood: str, prompt: str, query: str) -> MusicResult:
        hymn = select(mood=mood, prompt=prompt, query=query)
        slug, title, lyrics = hymn["slug"], hymn["title"], hymn["lyrics"]

        # 1. Locally-downloaded sung recording (preferred — no API cost).
        sung_key = f"hymns_my/{slug}.sung.mp3"
        if storage.exists(sung_key):
            return MusicResult(
                asset_type="audio",
                storage_key=sung_key,
                provider_ref=slug,
                title=title,
                lyrics=lyrics,
            )

        # 2. Cached Suno render from a previous generation / fall through to generate.
        key = CACHE_KEY.format(slug=slug)
        if not storage.exists(key):
            audio = sing(title=title, lyrics=lyrics, style=_STYLE)
            storage.upload_bytes(key, audio, "audio/mpeg")

        return MusicResult(
            asset_type="audio",
            storage_key=key,
            provider_ref=slug,
            title=title,
            lyrics=lyrics,
        )

