"""Sung Hymn Strategy — plays a local vintage/vocal-rendered track
with the localized verses on screen. Unified for English, Burmese, and Zolai.

Resolution order per language:
  td  → hymns_td/{slug}.sung.mp3  →  English audio + Tedim lyrics (fallback)
  my  → hymns_my/{slug}.sung.mp3  →  English audio + Burmese lyrics (fallback)
  en  → hymns/{slug}.sung.mp3     (public-domain 78rpm recording)
"""

from __future__ import annotations

import re

import storage
from . import MusicResult, MusicStrategy


def _norm_title(name: str) -> str:
    return re.sub(r"[^A-Z]", "", name.upper())[:80]


class SungHymnStrategy(MusicStrategy):
    """Serve a local vocal/sung hymn with on-screen lyrics."""

    def __init__(self, language: str = "en"):
        self.language = language

    def fetch(self, *, mood: str, prompt: str, query: str) -> MusicResult:
        from hymns import HYMNS
        from hymns import select as select_en

        title: str | None = None
        lyrics: str | None = None

        # 1. Try language-specific local audio
        if self.language == "td":
            from hymns_td import select as select_td
            hymn_local = select_td(mood=mood, prompt=prompt, query=query)
            slug = hymn_local["slug"]
            title = hymn_local["title"] + (
                f" ({hymn_local['title_en']})" if hymn_local.get("title_en") else ""
            )
            lyrics = hymn_local["lyrics"]
            local_key = f"hymns_td/{slug}.sung.mp3"
            if storage.exists(local_key):
                return MusicResult(
                    asset_type="audio",
                    storage_key=local_key,
                    provider_ref=f"sung_td_{slug}",
                    title=title,
                    lyrics=lyrics,
                )

        elif self.language == "my":
            from hymns_my import select as select_my
            hymn_local = select_my(mood=mood, prompt=prompt, query=query)
            slug = hymn_local["slug"]
            title = hymn_local["title"]
            lyrics = hymn_local["lyrics"]
            local_key = f"hymns_my/{slug}.sung.mp3"
            if storage.exists(local_key):
                return MusicResult(
                    asset_type="audio",
                    storage_key=local_key,
                    provider_ref=f"sung_my_{slug}",
                    title=title,
                    lyrics=lyrics,
                )

        # 2. English public-domain recording (primary for 'en'; fallback for 'my'/'td')
        eligible_en = {h["slug"] for h in HYMNS if storage.exists(f"hymns/{h['slug']}.sung.mp3")}
        if not eligible_en:
            raise RuntimeError("No local English sung hymns found. Please seed the library.")

        hymn_en = select_en(mood=mood, prompt=prompt, query=query, eligible=eligible_en)
        slug_en = hymn_en["slug"]
        key = f"hymns/{slug_en}.sung.mp3"

        if self.language == "en":
            title = hymn_en["title"]
            lyrics = hymn_en.get("lyrics", "")
            rec = hymn_en.get("recording", {})
            if rec:
                title = f"{title} · {rec.get('performer', 'Unknown')} ({rec.get('year', '')})"

        return MusicResult(
            asset_type="audio",
            storage_key=key,
            provider_ref=f"sung_{self.language}_{slug_en}",
            title=title,
            lyrics=lyrics,
        )
