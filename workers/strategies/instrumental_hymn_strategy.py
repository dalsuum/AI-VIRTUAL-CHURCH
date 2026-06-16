"""Instrumental Hymn Strategy — plays a local MIDI-rendered instrumental track
with the localized verses on screen. Unified for English, Burmese, and Zolai.

Resolution order per language:
  td  → hymns_td/{slug}.mp3  OR  hymns_td/inst/{NORM}.mp3  →  English MIDI + Tedim lyrics
  my  → hymns_my/{slug}.mp3  →  English MIDI + Burmese lyrics (fallback)
  en  → hymns/{slug}.mp3     (pre-seeded MIDI render)
"""

from __future__ import annotations

import functools
import json
import os
import re

import storage
from . import MusicResult, MusicStrategy

# Offline-built alignment of tedimhymn.com MIDI renders → hymn slugs, bridging the
# orthographic divergence the exact title-norm join misses (see tools/align_td_midi.py).
_TD_MAP_PATH = os.path.join(
    os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
    "data", "td_midi_slug_map.json",
)


@functools.lru_cache(maxsize=1)
def _td_slug_to_norm() -> dict[str, str]:
    """Inverse of the static map: hymn slug → MIDI <NORM> title. Empty if unbuilt."""
    try:
        with open(_TD_MAP_PATH, encoding="utf-8") as fh:
            return {slug: norm for norm, slug in json.load(fh).items()}
    except (FileNotFoundError, ValueError):
        return {}


def _norm_title(name: str) -> str:
    """Letters only, uppercased — matches the seeders' keying."""
    return re.sub(r"[^A-Z]", "", name.upper())[:80]


class InstrumentalHymnStrategy(MusicStrategy):
    """Serve a local MIDI instrumental hymn with on-screen lyrics."""

    def __init__(self, language: str = "en"):
        self.language = language

    def fetch(self, *, mood: str, prompt: str, query: str) -> MusicResult:
        from hymns import HYMNS
        from hymns import select as select_en

        title: str | None = None
        lyrics: str | None = None

        # 1. Try language-specific local audio
        if self.language == "td":
            from hymns_td import all_hymns
            from hymns_td import select as select_td
            # Restrict the pick to slugs whose instrumental MP3 is actually seeded,
            # so we stay in-language instead of silently falling through to English.
            # Two naming schemes are honored: slug-keyed `hymns_td/{slug}.mp3` and
            # the MIDI seeder's title-keyed `hymns_td/inst/{NORM}.mp3` (see
            # tools/seed_tedim_midi.py) — both map back to the hymn's slug here.
            seeded = storage.list_keys("hymns_td/")
            slug_to_norm = _td_slug_to_norm()

            def _td_keys(slug: str, title: str) -> tuple[str, ...]:
                """Candidate instrumental keys for a hymn, best first:
                slug-keyed render, exact title-norm render, then the offline
                fuzzy-aligned render (tools/align_td_midi.py)."""
                keys = [f"hymns_td/{slug}.mp3", f"hymns_td/inst/{_norm_title(title)}.mp3"]
                aligned = slug_to_norm.get(slug)
                if aligned:
                    keys.append(f"hymns_td/inst/{aligned}.mp3")
                return tuple(keys)

            eligible_td = {
                h["slug"]
                for h in all_hymns()
                if any(k in seeded for k in _td_keys(h["slug"], h["title"]))
            }
            hymn_local = select_td(
                mood=mood, prompt=prompt, query=query,
                eligible=eligible_td or None,
            )
            slug = hymn_local["slug"]
            title = hymn_local["title"] + (
                f" ({hymn_local['title_en']})" if hymn_local.get("title_en") else ""
            )
            lyrics = hymn_local["lyrics"]
            for local_key in _td_keys(slug, hymn_local["title"]):
                if storage.exists(local_key):
                    return MusicResult(
                        asset_type="audio",
                        storage_key=local_key,
                        provider_ref=f"inst_td_{slug}",
                        title=title,
                        lyrics=lyrics,
                    )

        elif self.language == "my":
            from hymns_my import select as select_my
            # Restrict the pick to slugs whose instrumental MP3 is actually seeded
            # (the downloaded subset), mirroring the English `eligible=` logic.
            seeded = storage.list_keys("hymns_my/")
            eligible_my = {
                k[len("hymns_my/"):-len(".mp3")]
                for k in seeded
                if k.endswith(".mp3") and not k.endswith(".sung.mp3")
            }
            hymn_local = select_my(
                mood=mood, prompt=prompt, query=query,
                eligible=eligible_my or None,
            )
            slug = hymn_local["slug"]
            title = hymn_local["title"]
            lyrics = hymn_local["lyrics"]
            local_key = f"hymns_my/{slug}.mp3"
            if storage.exists(local_key):
                return MusicResult(
                    asset_type="audio",
                    storage_key=local_key,
                    provider_ref=f"inst_my_{slug}",
                    title=title,
                    lyrics=lyrics,
                )

        # 2. English MIDI (primary for 'en'; fallback for 'my'/'td')
        eligible_en = {h["slug"] for h in HYMNS if storage.exists(f"hymns/{h['slug']}.mp3")}
        if not eligible_en:
            raise RuntimeError("No local English instrumental hymns found. Please seed the library.")

        hymn_en = select_en(mood=mood, prompt=prompt, query=query, eligible=eligible_en)
        slug_en = hymn_en["slug"]
        key = f"hymns/{slug_en}.mp3"

        if self.language == "en":
            title = hymn_en["title"]
            lyrics = hymn_en.get("lyrics", "")

        return MusicResult(
            asset_type="audio",
            storage_key=key,
            provider_ref=f"inst_{self.language}_{slug_en}",
            title=title,
            lyrics=lyrics,
        )
