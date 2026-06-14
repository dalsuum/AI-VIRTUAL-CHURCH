"""Tedim hymn strategy — a Tedim hymn, with the words on screen.

The seeded Tedim library (tools/seed_tedim_hymns.py) gives most hymns a
YouTube embed of the hymn actually being sung in Tedim — that is the preferred
playback: real Zomi voices, zero AI credit, exactly like YouTubeStrategy's
embeds. Selection order:

  1. hymns_td.select() picks a mood-appropriate hymn, biased toward entries
     that carry a YouTube id;
  2. if the chosen hymn has a YouTube id -> return a "youtube" MusicResult
     (provider_ref = video id) with the Tedim verses riding along in `lyrics`
     for on-screen display;
  3. otherwise -> sing the hymn's actual verses through Suno customMode and
     cache the render under hymns_td/<slug>.mp3, the same lazy-library pattern
     as the Myanmar strategy: first worshipper pays the credit, every later
     selection plays the stored file free.

  4. last resort — if tools/seed_tedim_midi.py has rendered the tune (optional
     MIDI instrumental), play that with the verses on screen, like the English
     `hymn` source.

If no path is possible (no YouTube id, Suno unavailable, no instrumental),
fetch() raises and generate_music skips the music segment gracefully — the
standard degradation path shared by every strategy.
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
        # Caption with both titles: the Tedim name worshippers know, plus the
        # English original it translates (e.g. "… (Blessed Assurance)").
        title = hymn["title"] + (f" ({hymn['title_en']})" if hymn.get("title_en") else "")

        if hymn.get("youtube_id"):
            # Embedded clip: no stored file, never pooled — mirrors YouTubeStrategy,
            # but unlike that strategy the hymn's verses ride along for display.
            return MusicResult(
                asset_type="youtube",
                provider_ref=hymn["youtube_id"],
                title=title,
                lyrics=lyrics,
            )

        key = CACHE_KEY.format(slug=slug)
        if not storage.exists(key):
            try:
                audio = sing(title=hymn["title"], lyrics=lyrics, style=_STYLE)
                storage.upload_bytes(key, audio, "audio/mpeg")
            except Exception:  # noqa: BLE001 — fall through to the instrumental
                inst = INSTRUMENTAL_KEY.format(norm=_norm_title(hymn["title"]))
                if not storage.exists(inst):
                    raise  # nothing left to play; generate_music skips music
                key = inst  # seeded tune render: instrumental + on-screen verses

        # RAW object key, like the other audio strategies; generate_music presigns
        # it. Never pooled by mood — the per-hymn cache above already reuses.
        return MusicResult(
            asset_type="audio",
            storage_key=key,
            provider_ref=slug,
            title=title,
            lyrics=lyrics,
        )
