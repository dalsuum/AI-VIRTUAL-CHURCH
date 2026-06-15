"""Hymn YouTube strategy — select a mood-appropriate hymn and find a vocal
(choir or congregational) performance of it on YouTube.

No audio is downloaded or stored; the Vue client embeds the official YouTube
player. Selection uses the same mood→hymn matcher as HymnStrategy so the right
hymn is chosen for the worshipper's feeling, but the performance is a real
human voice rather than a pre-seeded MIDI render.
"""

from __future__ import annotations

from hymns import select

from . import MusicResult, MusicStrategy
from .youtube_strategy import search_video


class HymnYouTubeStrategy(MusicStrategy):
    """Serve a vocal hymn from YouTube, mood-matched via the local hymn catalog."""

    def __init__(self, language: str = "en"):
        self.language = language

    def fetch(self, *, mood: str, prompt: str, query: str) -> MusicResult:
        # 1. Attempt local language search
        if self.language == "td":
            from hymns_td import select as select_td
            hymn_local = select_td(mood=mood, prompt=prompt, query=query, prefer_youtube=True)
            title_local = hymn_local["title"] + (f" ({hymn_local['title_en']})" if hymn_local.get("title_en") else "")
            
            # Tedim hymnal often has exact YouTube embeds already
            if hymn_local.get("youtube_id"):
                return MusicResult(
                    asset_type="youtube",
                    provider_ref=hymn_local["youtube_id"],
                    title=title_local,
                    lyrics=hymn_local["lyrics"],
                )
            try:
                yt_query = f"{hymn_local['title']} zomi christian song worship -instrumental".strip()
                result = search_video(query=yt_query, category_id="10")
                return MusicResult(
                    asset_type="youtube",
                    provider_ref=result["video_id"],
                    title=result["title"],
                    lyrics=hymn_local["lyrics"],
                )
            except Exception as e:
                print(f"[hymn-youtube] Zolai search failed: {e}. Falling back to English.", flush=True)
                
        elif self.language == "my":
            from hymns_my import select as select_my
            hymn_local = select_my(mood=mood, prompt=prompt, query=query)
            title_local = hymn_local["title"]
            try:
                yt_query = f"{title_local} ဓမ္မသီချင်း vocal choir -instrumental".strip()
                result = search_video(query=yt_query, category_id="10")
                return MusicResult(
                    asset_type="youtube",
                    provider_ref=result["video_id"],
                    title=result["title"],
                    lyrics=hymn_local["lyrics"],
                )
            except Exception as e:
                print(f"[hymn-youtube] Burmese search failed: {e}. Falling back to English.", flush=True)

        # 2. English Search (Default & Fallback)
        hymn_en = select(mood=mood, prompt=prompt, query=query)
        if hymn_en is None:
            raise RuntimeError("Hymn catalog is empty — check workers/hymns.py")

        mood_word = mood.lower().strip() if mood else ""
        yt_query = f"english choir {hymn_en['title']} hymn vocal {mood_word} -instrumental".strip()
        result = search_video(query=yt_query, category_id="10", english_only=True)
        
        # Determine the localized lyrics for the on-screen display if we fell back
        lyrics = hymn_en.get("lyrics", "")
        title = result["title"]

        if self.language == "td":
            try:
                from hymns_td import select as select_td
                hymn_fallback = select_td(mood=mood, prompt=prompt, query=query)
                lyrics = hymn_fallback["lyrics"]
                title = f"{hymn_fallback['title']} ({result['title']})"
            except Exception:
                pass
        elif self.language == "my":
            try:
                from hymns_my import select as select_my
                hymn_fallback = select_my(mood=mood, prompt=prompt, query=query)
                lyrics = hymn_fallback["lyrics"]
                title = f"{hymn_fallback['title']} ({result['title']})"
            except Exception:
                pass

        return MusicResult(
            asset_type="youtube",
            provider_ref=result["video_id"],
            title=title,
            lyrics=lyrics,
        )
