"""
YouTube strategy — find an existing worship track instead of generating one.

Uses the YouTube Data API v3 search endpoint. Returns the video id, which the Vue
client embeds via an <iframe> player. No audio is downloaded or stored: downloading
YouTube audio violates their Terms of Service, so we only ever embed the official
player. We also restrict to embeddable, licensed results.
"""

from __future__ import annotations

import os

import requests

from . import MusicResult, MusicStrategy


class YouTubeStrategy(MusicStrategy):
    SEARCH_URL = "https://www.googleapis.com/youtube/v3/search"

    def __init__(self) -> None:
        self.api_key = os.environ["YOUTUBE_API_KEY"]

    def fetch(self, *, mood: str, prompt: str, query: str) -> MusicResult:
        search = query or f"worship song {mood}"

        resp = requests.get(
            self.SEARCH_URL,
            params={
                "key": self.api_key,
                "part": "snippet",
                "q": search,
                "type": "video",
                "videoEmbeddable": "true",   # must be embeddable in our player
                "videoSyndicated": "true",   # playable outside youtube.com
                "videoCategoryId": "10",     # Music
                "safeSearch": "strict",
                "maxResults": 1,
            },
            timeout=30,
        )
        resp.raise_for_status()
        items = resp.json().get("items", [])

        if not items:
            raise LookupError(f"No embeddable YouTube result for query: {search!r}")

        item = items[0]
        return MusicResult(
            asset_type="youtube",
            provider_ref=item["id"]["videoId"],
            title=item["snippet"]["title"],
        )
