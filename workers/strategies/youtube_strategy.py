"""
YouTube strategy — find an existing worship track (and, in YouTube mode, an existing
preaching message) instead of generating them.

Uses the YouTube Data API v3 search endpoint. Returns the video id, which the Vue
client embeds via an <iframe> player. No audio/video is downloaded or stored:
downloading from YouTube violates their Terms of Service, so we only ever embed the
official player. We also restrict to embeddable, syndicated, safe-search results.
"""

from __future__ import annotations

import os

import requests

from . import MusicResult, MusicStrategy

SEARCH_URL = "https://www.googleapis.com/youtube/v3/search"


def search_video(*, query: str, category_id: str | None = None) -> dict:
    """Top embeddable, syndicated, safe-search video for `query`.

    Returns ``{"video_id", "title"}``; raises LookupError if nothing matches. Shared
    by the worship-music strategy and the YouTube-mode preaching lookup so both go
    through the same safety/embeddability constraints.
    """
    params = {
        "key": os.environ["YOUTUBE_API_KEY"],
        "part": "snippet",
        "q": query,
        "type": "video",
        "videoEmbeddable": "true",   # must be embeddable in our player
        "videoSyndicated": "true",   # playable outside youtube.com
        "safeSearch": "strict",
        "maxResults": 1,
    }
    if category_id:
        params["videoCategoryId"] = category_id

    resp = requests.get(SEARCH_URL, params=params, timeout=30)
    resp.raise_for_status()
    items = resp.json().get("items", [])
    if not items:
        raise LookupError(f"No embeddable YouTube result for query: {query!r}")

    item = items[0]
    return {"video_id": item["id"]["videoId"], "title": item["snippet"]["title"]}


def find_sermon_video(*, mood: str, query: str = "") -> dict:
    """Find an existing Christian preaching video for the worshipper's theme.

    Used in YouTube mode so the message is *sourced*, not AI-written — saving the
    sermon's LLM call (and any avatar/TTS for it). No category filter: sermons span
    People & Blogs, Education and Nonprofits, so we lean on safe-search + embeddable
    rather than a single music-style category.
    """
    return search_video(query=query or f"Christian sermon about {mood}")


class YouTubeStrategy(MusicStrategy):
    def fetch(self, *, mood: str, prompt: str, query: str) -> MusicResult:
        # Music category (10) so we get worship songs, not talks.
        result = search_video(query=query or f"worship song {mood}", category_id="10")
        return MusicResult(
            asset_type="youtube",
            provider_ref=result["video_id"],
            title=result["title"],
        )
