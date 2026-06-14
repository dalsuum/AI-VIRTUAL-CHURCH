"""
YouTube strategy — find an existing worship track (and, in YouTube mode, an existing
preaching message) instead of generating them.

Uses the YouTube Data API v3 search endpoint. Returns the video id, which the Vue
client embeds via an <iframe> player. No audio/video is downloaded or stored:
downloading from YouTube violates their Terms of Service, so we only ever embed the
official player. We also restrict to embeddable, syndicated, safe-search results.

## Adding a new language

Add one entry to ``_LANG_CONFIG`` (see below).  No other code changes required.
"""

from __future__ import annotations

import os
import random
import time
from typing import Callable

import requests

from . import MusicResult, MusicStrategy

SEARCH_URL = "https://www.googleapis.com/youtube/v3/search"


# ── script helpers ────────────────────────────────────────────────────────────

# Myanmar Unicode main block U+1000–U+109F.
def _has_myanmar_script(text: str) -> bool:
    return any(0x1000 <= ord(c) <= 0x109F for c in text)


# ── shared reject lists ───────────────────────────────────────────────────────

# South/Southeast Asian language channel keywords. Rejected whenever the service
# language has its own script or its own keyword set (Burmese, Tedim, …) so that
# Telugu, Tamil, Kannada etc. Christian content never appears in those services.
_SOUTH_ASIAN_CHANNEL_KEYWORDS = [
    "telugu", "tamil", "kannada", "malayalam", "hindi", "bengali",
    "sinhala", "sinhalese", "nepali", "marathi", "gujarati", "punjabi",
    "urdu", "odia", "assamese",
]

# English-mode channel exclusions (kept for backward-compat with english_only flag).
_EXCLUDED_CHANNEL_KEYWORDS = ["tamil", "hindi", "telugu", "malayalam", "kannada", "bengali"]

# Non-Christian religious terms — rejected regardless of query.
# This list is the built-in default; admins extend/trim it via the
# Content Filter panel in the Admin Console. The live list is fetched
# from the backend every 5 minutes and falls back to these defaults.
_DEFAULT_FILTER_KEYWORDS = [
    "buddhism", "buddhist", "buddha", "dharma", "sangha",
    "monk", "monks", "monastery", "zen",
    "hindu", "hinduism", "vedic",
    "islam", "islamic", "muslim", "quran", "quranic", "allah", "mosque",
    "rabbi", "synagogue", "jewish", "judaism", "torah",
    "new age", "wicca", "pagan", "occult", "astrology",
    "mindfulness", "chakra", "reincarnation",
]
# Backward-compat alias used in older imports.
_NON_CHRISTIAN_REJECT = _DEFAULT_FILTER_KEYWORDS

_filter_cache: list[str] = []
_filter_cache_ts: float = 0.0
_FILTER_TTL = 300  # seconds


def _get_filter_keywords() -> list[str]:
    """Return the admin-managed keyword reject list, refreshed every 5 minutes.

    Derives the backend config URL from LARAVEL_WEBHOOK_URL, calls /api/config,
    and caches the result in-process. Falls back to the built-in defaults on any
    network/parse error so filtering never stops working.
    """
    global _filter_cache, _filter_cache_ts
    now = time.monotonic()
    if _filter_cache and (now - _filter_cache_ts) < _FILTER_TTL:
        return _filter_cache
    try:
        webhook = os.environ.get("LARAVEL_WEBHOOK_URL", "")
        if "/api/" in webhook:
            config_url = webhook.split("/api/")[0] + "/api/config"
            resp = requests.get(config_url, timeout=5)
            resp.raise_for_status()
            keywords = resp.json().get("content_filter_keywords") or []
            if isinstance(keywords, list) and keywords:
                _filter_cache = [str(k).lower().strip() for k in keywords if k]
                _filter_cache_ts = now
                return _filter_cache
    except Exception:
        pass  # network error or env not set — use cached/default list
    return _filter_cache or _DEFAULT_FILTER_KEYWORDS


# ── per-language search configuration ────────────────────────────────────────
#
# To add a new language, add one entry here.  Fields:
#
#   relevance_language  BCP-47 code passed to YouTube as relevanceLanguage.
#                       Omit if YouTube has no useful code for this language.
#
#   script_check        Callable(title: str) -> bool.  Return True when the
#                       title is confirmed to be in the right script.  Used for
#                       languages that have a unique Unicode block (Myanmar, …).
#                       If provided, a result is rejected when it returns False.
#
#   required_title_terms  list[str] (lowercased).  At least one must appear in
#                         the video title.  Used for Latin-script languages that
#                         have no unique Unicode block (Tedim, Karen, …).
#
#   excluded_channels   list[str] (lowercased).  Channel names containing any
#                       of these substrings are rejected.
#
#   sermon_must_contain list[str] (lowercased for non-script, raw for script).
#                       The LLM-generated preaching_query must satisfy the same
#                       check that title filtering uses; if it does not, the
#                       query is replaced with sermon_fallback.
#
#   sermon_fallback     Search query used when the LLM query fails the
#                       sermon_must_contain check.  Include a {mood} placeholder
#                       if you want the worshipper's mood appended.

_LANG_CONFIG: dict[str, dict] = {
    "my": {
        # ██ Myanmar (Burmese) ██
        # Unique Unicode block → script check is the definitive gate.
        # Any result without Myanmar script in the title is rejected,
        # so Telugu, Tamil, English, and all other-script Christian videos
        # can never appear in a Burmese service.
        "relevance_language": "my",
        "script_check": _has_myanmar_script,
        "excluded_channels": _SOUTH_ASIAN_CHANNEL_KEYWORDS,
        # ခရစ်ယာန် = Christian, တရားဟောချက် = sermon, နုတ်ကပတ်တော် = Word of God
        "sermon_must_contain": ["ခရစ်ယာန်"],
        "sermon_fallback": "ခရစ်ယာန် တရားဟောချက် pastor sunday",
        # Sermon-only gate: title must ALSO contain at least one preaching indicator.
        # Prevents worship songs, kids' content, or general Myanmar videos from
        # appearing as the message segment just because they pass the script check.
        # တရားဟောချက် = sermon  နုတ်ကပတ်တော် = Word of God  သွန်သင်ချက် = teaching
        "sermon_title_require_any": [
            "တရားဟောချက်", "တရားဟော", "နုတ်ကပတ်တော်", "သွန်သင်ချက်",
            "pastor", "sunday", "rev", "rev.",
        ],
        # Ordered fallback queries tried in sequence when primary search returns nothing.
        "sermon_query_variants": [
            "ခရစ်ယာန် တရားဟောချက် နုတ်ကပတ်တော် pastor sunday",
            "ခရစ်ယာန် သွန်သင်ချက် နုတ်ကပတ်တော် sunday",
            "ခရစ်ယာန် တရားဟောချက် pastor rev sunday sermon",
            "Myanmar Christian sermon sunday pastor preaching",
        ],
    },
    "en": {
        # ██ English ██
        # No script check or required title terms — any English title is fine.
        # We do require "Christian" in the search query and at least one preaching
        # indicator in the title to prevent music videos, conferences, or motivational
        # content from appearing as the message segment.
        "relevance_language": "en",
        "excluded_channels": _EXCLUDED_CHANNEL_KEYWORDS,
        "sermon_must_contain": ["christian"],
        "sermon_fallback": "Christian sunday sermon pastor preaching",
        "sermon_title_require_any": [
            "sermon", "preaching", "message", "pastor", "rev", "rev.",
            "sunday", "teaching", "bible study", "gospel",
        ],
        "sermon_query_variants": [
            "Christian sunday sermon pastor preaching message",
            "Christian pastor sunday preaching bible sermon",
            "Christian sunday church sermon message",
            "Christian preaching sunday pastor gospel message",
        ],
    },
    "td": {
        # ██ Tedim / Zolai (Zomi Chin) ██
        # Latin script → keyword gate on title and query.
        # Zomi channels mostly use English metadata so we bias with English
        # relevanceLanguage and require one of the community's self-names.
        "relevance_language": "en",
        "required_title_terms": ["zomi", "tedim", "zolai", "chin christian"],
        "excluded_channels": _SOUTH_ASIAN_CHANNEL_KEYWORDS,
        "sermon_must_contain": ["zomi", "tedim", "zolai"],
        "sermon_fallback": "Zomi Tedim pastor sunday sermon preaching",
        # Sermon-only gate: title must ALSO contain at least one preaching indicator.
        # This prevents kids' songs, pop music, or worship tracks from appearing as
        # the "message" segment just because they contain "zomi" in the title.
        "sermon_title_require_any": [
            "sermon", "preaching", "message", "pastor", "rev", "rev.",
            "sunday", "thugenna", "thu gen", "thugen",
        ],
        # Ordered fallback queries tried in sequence when primary search returns nothing.
        "sermon_query_variants": [
            "Zomi pastor sunday preaching sermon message",
            "Zomi Tedim Christian sunday message pastor",
            "Zomi Chin Christian sermon preaching",
            "Tedim Zolai pastor preaching message",
        ],
    },
    # ── template for a future language ────────────────────────────────────────
    # "kn": {                          # example: Karen
    #     "relevance_language": "kar", # BCP-47 if known, else omit
    #     "required_title_terms": ["karen", "kayin", "sgaw"],
    #     "excluded_channels": _SOUTH_ASIAN_CHANNEL_KEYWORDS,
    #     "sermon_must_contain": ["karen", "kayin"],
    #     "sermon_fallback": "Karen Christian sermon",
    # },
}


# ── helpers ───────────────────────────────────────────────────────────────────

def _is_christian_result(item: dict) -> bool:
    snippet = item["snippet"]
    text = (snippet.get("title", "") + " " + snippet.get("channelTitle", "")).lower()
    return not any(kw in text for kw in _get_filter_keywords())


def _passes_language_filter(item: dict, cfg: dict) -> bool:
    """Return True when the item satisfies all per-language content gates."""
    title = item["snippet"].get("title", "")
    channel = item["snippet"].get("channelTitle", "").lower()

    # Channel exclusions
    for kw in cfg.get("excluded_channels", []):
        if kw in channel:
            return False

    # Script check (e.g. Myanmar Unicode required for Burmese)
    script_check: Callable[[str], bool] | None = cfg.get("script_check")
    if script_check and not script_check(title):
        return False

    # Keyword gate (e.g. "zomi"/"tedim" required in Tedim titles)
    required: list[str] = cfg.get("required_title_terms", [])
    if required and not any(t in title.lower() for t in required):
        return False

    return True


def _query_satisfies(query: str, cfg: dict) -> bool:
    """Return True when the search query already targets the right language."""
    must: list[str] = cfg.get("sermon_must_contain", [])
    if not must:
        return True
    script_check: Callable[[str], bool] | None = cfg.get("script_check")
    if script_check:
        # For script languages, verify the actual script, then optionally the term
        if not script_check(query):
            return False
        return any(t in query for t in must)
    return any(t in query.lower() for t in must)


# ── public API ────────────────────────────────────────────────────────────────

def search_video(
    *,
    query: str,
    category_id: str | None = None,
    english_only: bool = False,
    christian_only: bool = False,
    language: str = "en",
    excluded_ids: list[str] | None = None,
    sermon_title_require_any: list[str] | None = None,
) -> dict:
    """Embeddable, syndicated, safe-search video for ``query``.

    Returns ``{"video_id", "title"}``; raises LookupError if nothing matches.
    Shared by the worship-music strategy and the YouTube-mode preaching lookup.

    ``language`` drives both the YouTube ``relevanceLanguage`` hint and the
    post-filter gates defined in ``_LANG_CONFIG``.  Adding a new language to
    ``_LANG_CONFIG`` is the only change needed to extend filtering here.
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

    # relevanceLanguage: english_only wins; otherwise use per-language config.
    # christian_only alone must NOT force English — that broke Burmese mode.
    cfg = _LANG_CONFIG.get(language, {})
    if english_only:
        params["relevanceLanguage"] = "en"
    elif rl := cfg.get("relevance_language"):
        params["relevanceLanguage"] = rl

    if category_id:
        params["videoCategoryId"] = category_id

    resp = requests.get(SEARCH_URL, params=params, timeout=30)
    resp.raise_for_status()
    items = resp.json().get("items", [])
    if not items:
        raise LookupError(f"No embeddable YouTube result for query: {query!r}")

    excluded = set(excluded_ids or [])

    def _passes(item: dict) -> bool:
        if item["id"]["videoId"] in excluded:
            return False
        channel = item["snippet"].get("channelTitle", "").lower()
        if english_only and any(kw in channel for kw in _EXCLUDED_CHANNEL_KEYWORDS):
            return False
        if christian_only and not _is_christian_result(item):
            return False
        if cfg and not _passes_language_filter(item, cfg):
            return False
        if sermon_title_require_any:
            title_lower = item["snippet"].get("title", "").lower()
            if not any(t in title_lower for t in sermon_title_require_any):
                return False
        return True

    candidates = [item for item in items if _passes(item)]

    # If every candidate was excluded (returning worshipper, narrow results),
    # drop the exclusion set and retry filters — a repeat beats an error.
    if not candidates:
        excluded = set()
        candidates = [item for item in items if _passes(item)]
    if not candidates:
        raise LookupError(f"No suitable result after filtering for query: {query!r}")

    item = random.choice(candidates)
    return {"video_id": item["id"]["videoId"], "title": item["snippet"]["title"]}


def find_sermon_video(
    *, mood: str, query: str = "", language: str = "en", excluded_ids: list[str] | None = None
) -> dict:
    """Find an existing Christian preaching video for the worshipper's theme.

    Used in YouTube mode so the message is *sourced*, not AI-written.  Always
    enforces a Christian query term and filters non-Christian results.

    Language-specific behaviour is driven entirely by ``_LANG_CONFIG``: add a
    new entry there to extend filtering to a new language with zero code changes
    here.  For Burmese the query is forced to Myanmar script; for Tedim it must
    contain "Zomi"/"Tedim"/"Zolai" in the title *and* a preaching indicator
    (sermon/preaching/message/pastor/sunday/…) to prevent kids' songs or worship
    music from appearing as the message segment.

    Multiple query variants are tried in order when the primary search returns
    nothing suitable; see ``sermon_query_variants`` in ``_LANG_CONFIG``.
    """
    safe_query = query.strip()
    cfg = _LANG_CONFIG.get(language)

    sermon_title_require_any: list[str] | None = None

    if cfg:
        fallback = cfg.get("sermon_fallback", f"Christian sermon {mood}")
        sermon_title_require_any = cfg.get("sermon_title_require_any")
        if not safe_query or not _query_satisfies(safe_query, cfg):
            safe_query = f"{fallback} {mood}".strip()
        must = cfg.get("sermon_must_contain", [])
        script_check = cfg.get("script_check")
        if must and not _query_satisfies(safe_query, cfg):
            prefix = must[0] if not script_check else fallback.split()[0]
            safe_query = f"{prefix} {safe_query}"
    else:
        if not safe_query:
            safe_query = f"Christian sermon {mood}"
        if "christian" not in safe_query.lower():
            safe_query = f"Christian {safe_query}"

    # Build ordered list of queries to try: LLM query first, then config variants.
    variants: list[str] = [safe_query]
    if cfg:
        for v in cfg.get("sermon_query_variants", []):
            q = f"{v} {mood}".strip()
            if q not in variants:
                variants.append(q)

    last_exc: Exception = LookupError("no queries configured")
    for attempt_query in variants:
        try:
            return search_video(
                query=attempt_query,
                christian_only=True,
                language=language,
                excluded_ids=excluded_ids,
                sermon_title_require_any=sermon_title_require_any,
            )
        except LookupError as exc:
            last_exc = exc
            print(f"[sermon] query {attempt_query!r} returned no results, trying next variant", flush=True)

    raise last_exc


class YouTubeStrategy(MusicStrategy):
    def fetch(self, *, mood: str, prompt: str, query: str) -> MusicResult:
        # English choir hymn vocal — music category (10) filters out talks/sermons.
        # relevanceLanguage=en + channel filtering handled inside search_video.
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
