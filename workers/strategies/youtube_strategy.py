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
import random

import requests

from . import MusicResult, MusicStrategy

SEARCH_URL = "https://www.googleapis.com/youtube/v3/search"


_EXCLUDED_CHANNEL_KEYWORDS = ["tamil", "hindi", "telugu", "malayalam", "kannada", "bengali"]

# South/Southeast Asian language channel keywords — rejected for Burmese services.
# Telugu, Tamil, Kannada etc. produce Christian content that passes the religion
# filter but is in the wrong language for a Myanmar-language service.
_SOUTH_ASIAN_CHANNEL_KEYWORDS = [
    "telugu", "tamil", "kannada", "malayalam", "hindi", "bengali",
    "sinhala", "sinhalese", "nepali", "marathi", "gujarati", "punjabi",
    "urdu", "odia", "assamese",
]

# Non-Christian religious terms — any video whose title or channel contains one of
# these is rejected regardless of query. Keeps Buddhist, Islamic, Hindu, and New Age
# content out of a Baptist/AG Christian service.
_NON_CHRISTIAN_REJECT = [
    "buddhism", "buddhist", "buddha", "dharma", "sangha",
    "monk", "monks", "monastery", "zen",
    "hindu", "hinduism", "vedic",
    "islam", "islamic", "muslim", "quran", "quranic", "allah", "mosque",
    "rabbi", "synagogue", "jewish", "judaism", "torah",
    "new age", "wicca", "pagan", "occult", "astrology",
    "mindfulness", "chakra", "reincarnation",
]

# Myanmar Unicode main block: U+1000–U+109F.  Any title that contains at least
# one character in this range is confirmed Burmese-script content.
_MYANMAR_SCRIPT_START = 0x1000
_MYANMAR_SCRIPT_END = 0x109F


def _has_myanmar_script(text: str) -> bool:
    return any(_MYANMAR_SCRIPT_START <= ord(c) <= _MYANMAR_SCRIPT_END for c in text)


def _is_christian_result(item: dict) -> bool:
    """Return False if the video title or channel name matches a non-Christian religion."""
    snippet = item["snippet"]
    text = (snippet.get("title", "") + " " + snippet.get("channelTitle", "")).lower()
    return not any(kw in text for kw in _NON_CHRISTIAN_REJECT)


def _is_burmese_result(item: dict) -> bool:
    """Return True only when the video title contains Myanmar Unicode characters.

    This is the definitive gate for Burmese-language services: Telugu, Tamil,
    Hindi, English, and every other non-Myanmar-script video is rejected here
    even if it is genuine Christian content, because the worshipper cannot
    understand it and it will look like a bug.
    """
    title = item["snippet"].get("title", "")
    return _has_myanmar_script(title)


def search_video(
    *,
    query: str,
    category_id: str | None = None,
    english_only: bool = False,
    christian_only: bool = False,
    language: str = "en",
    excluded_ids: list[str] | None = None,
) -> dict:
    """Embeddable, syndicated, safe-search video for `query`.

    Returns ``{"video_id", "title"}``; raises LookupError if nothing matches. Shared
    by the worship-music strategy and the YouTube-mode preaching lookup so both go
    through the same safety/embeddability constraints.

    Fetches up to 25 candidates, applies filters, then picks randomly from the
    survivors so the same video does not repeat across consecutive services.
    ``excluded_ids`` lets the caller skip videos the worshipper has already seen.
    ``language`` controls relevanceLanguage and script-based post-filtering so that
    Burmese services only receive Burmese-script results.
    """
    params = {
        "key": os.environ["YOUTUBE_API_KEY"],
        "part": "snippet",
        "q": query,
        "type": "video",
        "videoEmbeddable": "true",
        "videoSyndicated": "true",
        "safeSearch": "strict",
        "maxResults": 25,
    }
    # relevanceLanguage biases results toward the service language.
    # NOTE: christian_only alone must NOT force English — that broke Burmese mode.
    if english_only:
        params["relevanceLanguage"] = "en"
    elif language == "my":
        params["relevanceLanguage"] = "my"
    # Tedim uses Latin script; no dedicated BCP-47 code — omit to let YouTube rank freely.

    if category_id:
        params["videoCategoryId"] = category_id

    resp = requests.get(SEARCH_URL, params=params, timeout=30)
    resp.raise_for_status()
    items = resp.json().get("items", [])
    if not items:
        raise LookupError(f"No embeddable YouTube result for query: {query!r}")

    excluded = set(excluded_ids or [])

    def _passes(item: dict) -> bool:
        vid = item["id"]["videoId"]
        if vid in excluded:
            return False
        channel = item["snippet"].get("channelTitle", "").lower()
        # English-only: reject South/Southeast Asian language channels
        if english_only and any(kw in channel for kw in _EXCLUDED_CHANNEL_KEYWORDS):
            return False
        # Burmese: reject South Asian channels (Telugu, Tamil, Kannada, etc.)
        if language == "my" and any(kw in channel for kw in _SOUTH_ASIAN_CHANNEL_KEYWORDS):
            return False
        # Religion filter — always active when requested
        if christian_only and not _is_christian_result(item):
            return False
        # Burmese: require Myanmar Unicode script in the title
        if language == "my" and not _is_burmese_result(item):
            return False
        return True

    candidates = [item for item in items if _passes(item)]

    # If every candidate was excluded (returning worshipper, narrow results), ignore
    # the exclusion list rather than failing — a repeat is better than an error.
    if not candidates:
        excluded = set()  # clear exclusions and retry the full filter pass
        candidates = [item for item in items if _passes(item)]
    if not candidates:
        raise LookupError(f"No suitable result after filtering for query: {query!r}")

    item = random.choice(candidates)
    return {"video_id": item["id"]["videoId"], "title": item["snippet"]["title"]}


def find_sermon_video(
    *, mood: str, query: str = "", language: str = "en", excluded_ids: list[str] | None = None
) -> dict:
    """Find an existing Christian preaching video for the worshipper's theme.

    Used in YouTube mode so the message is *sourced*, not AI-written — saving the
    sermon's LLM call (and any avatar/TTS for it). No category filter: sermons span
    People & Blogs, Education and Nonprofits, so we lean on safe-search + embeddable
    rather than a single music-style category.

    Always enforces "Christian" in the query and filters out non-Christian religious
    results (Buddhist, Islamic, Hindu, etc.) so no other religion's content appears
    in a Baptist/AG church service. For Burmese (language="my") the query is also
    forced to include Myanmar-script Christian terms, and results are rejected unless
    their title contains Myanmar Unicode — this is the definitive fix that prevents
    Telugu, Tamil, and other South Asian Christian videos from appearing in a
    Burmese-language service. ``excluded_ids`` skips videos the worshipper has
    already been shown so the same sermon never repeats.
    """
    safe_query = query.strip() if query.strip() else ""

    if language == "my":
        # ခရစ်ယာန် = Christian (Myanmar), တရားဟောချက် = sermon/message
        if not _has_myanmar_script(safe_query):
            # LLM fallback produced an English query — replace with a strong Burmese one
            safe_query = f"ခရစ်ယာန် တရားဟောချက် {mood}"
        elif "ခရစ်ယာန်" not in safe_query:
            safe_query = f"ခရစ်ယာန် {safe_query}"
    else:
        if not safe_query:
            safe_query = f"Christian sermon {mood}"
        if "christian" not in safe_query.lower():
            safe_query = f"Christian {safe_query}"

    return search_video(
        query=safe_query, christian_only=True, language=language, excluded_ids=excluded_ids
    )


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
            christian_only=True,
        )
        return MusicResult(
            asset_type="youtube",
            provider_ref=result["video_id"],
            title=result["title"],
        )
