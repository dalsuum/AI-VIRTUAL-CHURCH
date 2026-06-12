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

    def fetch(self, *, mood: str, prompt: str, query: str) -> MusicResult:
        hymn = select(mood=mood, prompt=prompt, query=query)
        if hymn is None:
            raise RuntimeError("Hymn catalog is empty — check workers/hymns.py")

        # Build a query that carries the worshipper's mood so YouTube surfaces
        # versions that match the emotional context (e.g. a tender "Amazing Grace"
        # for grief vs. a jubilant one for gratitude). "vocal choir" keeps results
        # as sung performances rather than instrumental renders.
        mood_word = mood.lower().strip() if mood else ""
        yt_query = f"english choir {hymn['title']} hymn vocal {mood_word} -instrumental".strip()
        result = search_video(query=yt_query, category_id="10", english_only=True)
        return MusicResult(
            asset_type="youtube",
            provider_ref=result["video_id"],
            title=result["title"],
            lyrics=None,
        )
