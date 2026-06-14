"""
YouTube search strategy for music and sermons.

Uses the YouTube Data API v3 to find embeddable worship tracks and sermon
videos.  All functions are synchronous — they are called from Celery tasks
that have no event loop.  Do NOT make them async without also updating every
Celery caller.

## Adding a new language

Add one entry to _LANG_CONFIG.  No other code changes are required here.
Update strategies/__init__.py get_strategy() if the new language needs
routing (e.g. to a different strategy class for hymn sources).

## Filter pipeline (both music and sermon slots)

  music slot :
    1. music_title_require_any  — at least one worship/Christian term in title
    2. music_title_reject_any   — reject non-worship content (cartoons, etc.)
    3. channel_reject_any       — reject off-topic channels
    4. mood scoring             — rank remaining by mood keyword matches

  sermon slot :
    1. sermon_title_require_any — at least one preaching indicator (word-boundary)
    2. sermon_title_reject_any  — reject music/choir/concert events (word-boundary)
    3. channel_reject_any       — reject off-topic channels

"sunday" is intentionally absent from every sermon_title_require_any list —
it caused "Mission Sunday Choir" events to appear as the sermon segment.
"""

from __future__ import annotations

import os
import re

import requests

from . import MusicResult, MusicStrategy

YOUTUBE_API_KEY = os.getenv("YOUTUBE_API_KEY")
YOUTUBE_API_URL = "https://www.googleapis.com/youtube/v3/search"


def is_enabled() -> bool:
    return bool(YOUTUBE_API_KEY)


# ── per-language configuration ─────────────────────────────────────────────────

_LANG_CONFIG: dict[str, dict] = {
    "en": {
        "query_anchor": "christian",
        # ── sermon filters ──
        # "sunday" excluded: "Sunday Choir" / "Sunday Concert" titles pass it.
        "sermon_title_require_any": [
            "sermon", "preaching", "message", "pastor", "rev", "teaching",
            "bible study", "gospel",
        ],
        "sermon_title_reject_any": [
            "choir", "concert", "song", "music", "worship service live",
            "full service", "reaction",
        ],
        # ── music filters ──
        "music_title_require_any": [
            "worship", "praise", "christian", "church", "hymn", "gospel",
        ],
        "music_title_reject_any": [
            "reaction", "review", "live stream", "podcast", "interview", "top 10",
        ],
        "channel_reject_any": ["movie clips", "gaming", "news", "politics"],
        "music_mood_keywords": {
            "Grateful": ["praise", "thanksgiving", "grateful", "blessed"],
            "Anxious": ["peace", "comfort", "trust", "do not be afraid", "still"],
            "Grieving": ["hope", "comfort", "loss", "sorrow", "healing"],
            "Joyful": ["joy", "celebration", "rejoice", "victory"],
            "Seeking": ["guidance", "wisdom", "seeking", "draw me close"],
            "Hopeful": ["hope", "faith", "future", "promise"],
        },
    },
    "my": {
        # ██ Myanmar (Burmese) ██
        "query_anchor": "ခရစ်ယာန်",
        # ── sermon filters ──
        # တရားဟောချက် = sermon  နုတ်ကပတ်တော် = Word of God  သွန်သင်ချက် = teaching
        # "sunday" excluded: "Mission Sunday ကော်ရပ်" events contain it.
        "sermon_title_require_any": [
            "တရားဟောချက်", "တရားဟော", "နုတ်ကပတ်တော်", "သွန်သင်ချက်", "pastor", "rev",
        ],
        # ကော်ရပ် = choir  သီချင်း = song  ဓမ္မသီချင်း = gospel song  ဂီတ = music
        "sermon_title_reject_any": [
            "ကော်ရပ်", "သီချင်း", "ဓမ္မသီချင်း", "ဂီတ",
            "concert", "choir", "song", "music", "album",
        ],
        # ── music filters ──
        # ခရစ်ယာန် = Christian  ဝတ်ပြု = worship  ချီးမွမ်း = praise
        "music_title_require_any": [
            "ခရစ်ယာန်", "ဝတ်ပြု", "ချီးမွမ်း", "ဓမ္မသီချင်း", "worship", "christian",
        ],
        "music_title_reject_any": ["reaction", "interview", "podcast", "album"],
        "channel_reject_any": [
            "telugu", "tamil", "kannada", "hindi", "malayalam",
            "movie", "film", "news", "entertainment", "celebrity",
        ],
        "music_mood_keywords": {
            "Grateful": ["ကျေးဇူးတော်", "ချီးမွမ်း"],
            "Anxious": ["ငြိမ်သက်ခြင်း", "နှစ်သိမ့်"],
            "Grieving": ["မျှော်လင့်ခြင်း", "နှစ်သိမ့်", "ကုသ"],
            "Joyful": ["ဝမ်းမြောက်ခြင်း", "ပျော်ရွှင်"],
            "Seeking": ["လမ်းပြ", "ပညာ"],
            "Hopeful": ["မျှော်လင့်ခြင်း", "ယုံကြည်ခြင်း"],
        },
    },
    "td": {
        # ██ Tedim / Zolai (Zomi Chin) ██
        # Latin script → keyword gates on both title and query.
        "query_anchor": "zomi christian",
        # ── sermon filters ──
        # "sunday" excluded: "Mission Sunday" choir events contain it.
        # thugenna / thu gen / thugen = preaching/sermon in Zomi.
        "sermon_title_require_any": [
            "sermon", "preaching", "message", "pastor", "rev",
            "thugenna", "thu gen", "thugen",
        ],
        # lasa = songs (Tedim); la = song — use word-boundary matching (see below).
        "sermon_title_reject_any": [
            "lasa", "la", "choir", "concert", "song", "music", "zomi idol", "album",
        ],
        # ── music filters ──
        # Require at least one Christian/worship term so cartoon/drama videos are blocked.
        # pasian = God (Tedim), topa = Lord, zeisu = Jesus, krist = Christ.
        "music_title_require_any": [
            "christian", "worship", "praise", "church",
            "pasian", "topa", "zeisu", "krist",
        ],
        # cartoon, animation, drama, film — "Zomi Song 2015" was a cartoon thumbnail.
        "music_title_reject_any": [
            "reaction", "interview", "podcast", "vlog", "album",
            "cartoon", "animation", "movie", "drama", "film",
        ],
        "channel_reject_any": [
            "telugu", "tamil", "kannada", "hindi", "malayalam",
            "movie", "film", "news", "mizo", "haka", "falam",
        ],
        "music_mood_keywords": {
            "Grateful": ["lungdam", "phatna"],
            "Anxious": ["lungmuanna", "khamuanna"],
            "Grieving": ["lungkham", "hehnepna"],
            "Joyful": ["nopna", "kipahna"],
            "Seeking": ["makaihna", "pilna"],
            "Hopeful": ["lam-etna", "ginna"],
        },
    },
}


# ── shared YouTube API helper ──────────────────────────────────────────────────

def _search_youtube(query: str, **params) -> list[dict]:
    """Call the YouTube Search API and return raw items (synchronous).

    Callers are Celery tasks — do NOT make this async.
    """
    if not is_enabled():
        raise RuntimeError("YouTube API key is not configured.")
    resp = requests.get(
        YOUTUBE_API_URL,
        params={
            "key": YOUTUBE_API_KEY,
            "q": query,
            "part": "snippet",
            "type": "video",
            "maxResults": 20,
            "safeSearch": "strict",
            "videoEmbeddable": "true",
            **params,
        },
        timeout=30,
    )
    resp.raise_for_status()
    return resp.json().get("items", [])


def _wb(kw: str, title: str) -> bool:
    """Return True if `kw` appears as a whole word in `title`."""
    return bool(re.search(r"\b" + re.escape(kw) + r"\b", title))


# ── public music strategy ──────────────────────────────────────────────────────

class YouTubeStrategy(MusicStrategy):
    """Find a modern worship track on YouTube that matches the user's mood.

    Scored by mood-keyword density so the most on-theme result wins rather
    than the first result that passes the filter.
    """

    def __init__(self, language: str = "en") -> None:
        self.language = language

    def fetch(self, *, mood: str, prompt: str, query: str) -> MusicResult:
        lang_conf = _LANG_CONFIG.get(self.language, _LANG_CONFIG["en"])

        queries = [
            query,
            f"{lang_conf['query_anchor']} worship song {mood}",
        ]

        music_require: list[str] = lang_conf.get("music_title_require_any", [])
        music_reject: list[str] = lang_conf.get("music_title_reject_any", [])
        channel_reject: list[str] = lang_conf.get("channel_reject_any", [])
        mood_keywords: list[str] = lang_conf.get("music_mood_keywords", {}).get(mood, [])

        best_video = None
        best_score = -1

        for q in queries:
            if not q:
                continue
            try:
                results = _search_youtube(q, videoCategoryId="10")  # 10 = Music
            except Exception as exc:
                print(f"[youtube-music] search failed for {q!r}: {exc}", flush=True)
                continue

            for item in results:
                snippet = item.get("snippet", {})
                title = (snippet.get("title") or "").lower()
                channel = (snippet.get("channelTitle") or "").lower()
                desc = (snippet.get("description") or "").lower()

                # Gate 1: must contain at least one Christian/worship term.
                if music_require and not any(kw in title for kw in music_require):
                    continue
                # Gate 2: reject non-worship content.
                if any(kw in title for kw in music_reject):
                    continue
                # Gate 3: reject off-topic channels.
                if any(kw in channel for kw in channel_reject):
                    continue

                # Score by mood relevance; title matches outweigh description.
                score = sum(2 if kw in title else (1 if kw in desc else 0)
                            for kw in mood_keywords)

                if score > best_score:
                    best_score = score
                    best_video = item

            # Stop after first query that yields a mood-matched result.
            if best_video and best_score > 0:
                break

        if not best_video:
            raise RuntimeError(f"No suitable YouTube worship music found for mood {mood!r}")

        return MusicResult(
            asset_type="youtube",
            provider_ref=best_video["id"]["videoId"],
            title=best_video["snippet"]["title"],
        )


# ── public sermon lookup ───────────────────────────────────────────────────────

def find_sermon_video(
    mood: str,
    query: str,
    language: str,
    excluded_ids: list[str] | None = None,
) -> dict:
    """Find a Christian preaching video for the worshipper's theme (synchronous).

    Called from sync Celery tasks — do NOT make this async.
    Returns {"video_id": str, "title": str}; raises RuntimeError if nothing passes.
    """
    lang_conf = _LANG_CONFIG.get(language, _LANG_CONFIG["en"])
    excluded = set(excluded_ids or [])

    anchor = lang_conf["query_anchor"]
    queries = [
        query,
        f"{anchor} sermon {mood}",
        f"{anchor} preaching {mood}",
        f"{anchor} message of hope",
        f"{anchor} sermon",
    ]

    require: list[str] = lang_conf.get("sermon_title_require_any", [])
    reject: list[str] = lang_conf.get("sermon_title_reject_any", [])
    channel_reject: list[str] = lang_conf.get("channel_reject_any", [])

    for q in queries:
        if not q:
            continue
        try:
            results = _search_youtube(q)
        except Exception as exc:
            print(f"[sermon] query {q!r} failed: {exc}", flush=True)
            continue

        for item in results:
            video_id = item.get("id", {}).get("videoId")
            if not video_id or video_id in excluded:
                continue

            snippet = item.get("snippet", {})
            title = (snippet.get("title") or "").lower()
            channel = (snippet.get("channelTitle") or "").lower()

            # Gate 1: title must contain a preaching indicator (whole-word match).
            if require and not any(_wb(kw, title) for kw in require):
                continue
            # Gate 2: title must NOT contain a music/choir/event keyword (whole-word).
            if any(_wb(kw, title) for kw in reject):
                continue
            # Gate 3: reject off-topic channels.
            if any(kw in channel for kw in channel_reject):
                continue

            return {"video_id": video_id, "title": snippet["title"]}

    raise RuntimeError(f"No suitable YouTube sermon found for mood {mood!r} in language {language!r}")
