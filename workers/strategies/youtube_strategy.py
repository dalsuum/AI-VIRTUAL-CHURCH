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


_EXCLUDED_CHANNEL_KEYWORDS = ["tamil", "hindi", "telugu", "malayalam", "kannada", "bengali"]


def search_video(
    *,
    query: str,
    category_id: str | None = None,
    english_only: bool = False,
) -> dict:
    """Top embeddable, syndicated, safe-search video for `query`.

    Returns ``{"video_id", "title"}``; raises LookupError if nothing matches. Shared
    by the worship-music strategy and the YouTube-mode preaching lookup so both go
    through the same safety/embeddability constraints.

    When ``english_only=True``, fetches up to 10 candidates and skips any whose
    channel title contains a non-English language keyword (Tamil, Hindi, etc.).
    """
    params = {
        "key": os.environ["YOUTUBE_API_KEY"],
        "part": "snippet",
        "q": query,
        "type": "video",
        "videoEmbeddable": "true",   # must be embeddable in our player
        "videoSyndicated": "true",   # playable outside youtube.com
        "safeSearch": "strict",
        "maxResults": 10 if english_only else 1,
    }
    if english_only:
        params["relevanceLanguage"] = "en"
    if category_id:
        params["videoCategoryId"] = category_id

    resp = requests.get(SEARCH_URL, params=params, timeout=30)
    resp.raise_for_status()
    items = resp.json().get("items", [])
    if not items:
        raise LookupError(f"No embeddable YouTube result for query: {query!r}")

    if english_only:
        for item in items:
            channel = item["snippet"].get("channelTitle", "").lower()
            if not any(kw in channel for kw in _EXCLUDED_CHANNEL_KEYWORDS):
                return {"video_id": item["id"]["videoId"], "title": item["snippet"]["title"]}

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
        # "english choir hymn vocal" targets choral vocal performances and keeps out
        # instrumental-only and non-English (Tamil, Hindi, etc.) results.
        # relevanceLanguage=en + channel filtering handled inside search_video.
        # Music category (10) filters out talks and sermons.
        result = search_video(
            query=query or f"english choir hymn vocal worship {mood} -instrumental",
            category_id="10",
            english_only=True,
        )
        return MusicResult(
            asset_type="youtube",
            provider_ref=result["video_id"],
            title=result["title"],
        )
