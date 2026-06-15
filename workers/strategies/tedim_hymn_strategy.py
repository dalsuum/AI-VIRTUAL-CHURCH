"""Tedim hymn strategy — a Tedim hymn, with the words on screen.

NOTE: The active router (strategies/__init__.py) routes `hymn_sung` and `hymn`
through the unified SungHymnStrategy / InstrumentalHymnStrategy instead.
This class is kept for reference and legacy compatibility only.

Resolution order (updated to prefer locally-downloaded files):
  1. hymns_td/<slug>.sung.mp3  — locally downloaded sung recording (preferred)
  2. YouTube embed via hymn["youtube_id"] if present
  3. hymns_td/<slug>.mp3       — cached Suno render from a previous generation
  4. Suno API                  — generate on-the-fly and cache
  5. hymns_td/inst/<NORM>.mp3  — seeded MIDI instrumental (last resort)
"""

from __future__ import annotations

import os
import re

import storage
from hymns_td import select

from . import MusicResult, MusicStrategy
from ._suno_custom import sing

CACHE_KEY = "hymns_td/{slug}.mp3"
INSTRUMENTAL_KEY = "hymns_td/inst/{norm}.mp3"  # seeded by tools/seed_tedim_midi.py


def _norm_title(name: str) -> str:
    """Letters only, uppercased — matches seed_tedim_midi.py's keying."""
    return re.sub(r"[^A-Z]", "", name.upper())[:80]

_STYLE = os.getenv(
    "SUNO_TD_STYLE",
    "Traditional Christian hymn, reverent congregational choir with piano and organ, "
    "sung in the Tedim Chin (Zomi) language",
)


class TedimHymnStrategy(MusicStrategy):
    """Mood-matched Tedim hymn: YouTube embed when we have one, else a cached Suno render."""

    def fetch(self, *, mood: str, prompt: str, query: str) -> MusicResult:
        hymn = select(mood=mood, prompt=prompt, query=query)
        slug, lyrics = hymn["slug"], hymn["lyrics"]
        title = hymn["title"] + (f" ({hymn['title_en']})" if hymn.get("title_en") else "")

        # 1. Locally-downloaded sung recording (preferred — no API cost).
        sung_key = f"hymns_td/{slug}.sung.mp3"
        if storage.exists(sung_key):
            return MusicResult(
                asset_type="audio",
                storage_key=sung_key,
                provider_ref=slug,
                title=title,
                lyrics=lyrics,
            )

        # 2. YouTube embed when the hymnal carries one.
        if hymn.get("youtube_id"):
            return MusicResult(
                asset_type="youtube",
                provider_ref=hymn["youtube_id"],
                title=title,
                lyrics=lyrics,
            )

        # 3. Cached Suno render / generate on-the-fly.
        key = CACHE_KEY.format(slug=slug)
        if not storage.exists(key):
            try:
                audio = sing(title=hymn["title"], lyrics=lyrics, style=_STYLE)
                storage.upload_bytes(key, audio, "audio/mpeg")
            except Exception:  # noqa: BLE001 — fall through to the instrumental
                inst = INSTRUMENTAL_KEY.format(norm=_norm_title(hymn["title"]))
                if not storage.exists(inst):
                    raise  # nothing left to play; generate_music skips music
                key = inst

        return MusicResult(
            asset_type="audio",
            storage_key=key,
            provider_ref=slug,
            title=title,
            lyrics=lyrics,
        )
