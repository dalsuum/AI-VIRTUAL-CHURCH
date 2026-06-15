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
    1. sermon_title_require_any — at least one preaching indicator
       (word-boundary for Latin scripts, substring for non-Latin scripts)
    2. sermon_title_reject_any  — reject music/choir/concert events (word-boundary)
    3. channel_reject_any       — reject off-topic channels

"sunday" is intentionally absent from every sermon_title_require_any list —
it caused "Mission Sunday Choir" events to appear as the sermon segment.
"""

from __future__ import annotations

import os
import re
import random
import time

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
        # Target modern worship artists — sampled randomly per request for variety.
        "preferred_artists": [
            "Hillsong", "Planetshakers", "Elevation Worship", "Bethel Music",
            "Maverick City Music", "Don Moen", "Chris Tomlin", "Phil Wickham",
        ],
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
        # Target Burmese modern worship artists — sampled randomly per request for variety.
        "preferred_artists": [
            "Grace Full Gospel", "Thang Taung", "Sangpi",
            "David Lah 100% Jesus", "Kaung Kaung", "Susanna Min", "Khual Pi",
        ],
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
        # Target Zomi/Tedim modern worship artists — sampled randomly per request for variety.
        "preferred_artists": [
            "Thang Taung", "Zomi Worship Collective", "Phillip Ruth",
            "We Worship", "FEMC Worship", "ZACC Worship", "Khai Pi", "Cin Bawi",
        ],
    },
}


# ── shared YouTube API helper ──────────────────────────────────────────────────

def _search_youtube(query: str, **params) -> list[dict]:
    """Call the YouTube Search API and return raw items (synchronous).

    Callers are Celery tasks — do NOT make this async.
    """
    if not is_enabled():
        raise RuntimeError("YouTube API key is not configured.")
    
    max_retries = 3
    for attempt in range(1, max_retries + 1):
        try:
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
        except requests.RequestException as exc:
            if attempt == max_retries:
                raise
            # Do not retry on client errors that are likely permanent, except 429 Too Many Requests
            if isinstance(exc, requests.HTTPError) and exc.response is not None:
                if 400 <= exc.response.status_code < 500 and exc.response.status_code != 429:
                    raise
            sleep_time = 2 ** (attempt - 1)
            print(f"[youtube] API fetch failed: {exc} — retrying in {sleep_time}s...", flush=True)
            time.sleep(sleep_time)


def search_video(query: str, category_id: str | None = None, english_only: bool = False) -> dict:
    """Compatibility helper for strategies that need one embeddable YouTube video.

    Returns {"video_id": str, "title": str}. Kept synchronous because Celery
    callers run without an event loop.
    """
    params = {}
    if category_id:
        params["videoCategoryId"] = category_id
    if english_only:
        params.update({"relevanceLanguage": "en", "regionCode": "US"})

    for item in _search_youtube(query, **params):
        video_id = item.get("id", {}).get("videoId")
        snippet = item.get("snippet", {})
        title = snippet.get("title") or ""
        if video_id and title:
            return {"video_id": video_id, "title": title}

    raise RuntimeError(f"No suitable YouTube video found for query {query!r}")


def _wb(kw: str, title: str) -> bool:
    """Return True if `kw` appears as a whole word in `title`."""
    return bool(re.search(r"\b" + re.escape(kw) + r"\b", title))


def _has_myanmar(text: str) -> bool:
    """Return True when text contains Myanmar Unicode script."""
    return re.search(r"[\u1000-\u109F]", text or "") is not None


# Words that unambiguously identify a video as Zomi/Tedim content.
# We check these against the lowercased title before applying the generic
# sermon-indicator gate, mirroring the Myanmar-script check for Burmese.
_TEDIM_IDENTITY_WORDS = frozenset([
    "zomi", "tedim", "zolai",
    "thugenna", "thugen",  # sermon/preaching in Tedim
    "thu gen",             # two-word form of preaching
])


def _has_tedim_identity(text: str) -> bool:
    """Return True when text contains a Zomi/Tedim community or language marker."""
    lower = (text or "").lower()
    return any(word in lower for word in _TEDIM_IDENTITY_WORDS)


def _keyword_hit(kw: str, text: str) -> bool:
    """Match Latin keywords on word boundaries; non-Latin keywords by substring."""
    return _wb(kw, text) if kw.isascii() else kw in text


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

        anchor = lang_conf.get("query_anchor", "christian")
        preferred_artists = lang_conf.get("preferred_artists", [])

        queries = [
            query,
            f"{query} worship" if query else "",
            f"{anchor} worship song {query}" if query else "",
            f"{anchor} worship song {mood}",
        ]
        # Add a randomly chosen artist-anchored query for mood-matched variety.
        # Inserted at the front so it's tried first; a second artist goes at the end
        # as a final fallback, ensuring different users get different songs.
        if preferred_artists:
            artist_a = random.choice(preferred_artists)
            artist_b = random.choice(preferred_artists)
            queries.insert(0, f"{artist_a} {mood.lower()} worship")
            queries.append(f"{artist_b} worship song")

        queries = list(dict.fromkeys(q for q in queries if q))

        music_require: list[str] = lang_conf.get("music_title_require_any", [])
        music_reject: list[str] = lang_conf.get("music_title_reject_any", [])
        channel_reject: list[str] = lang_conf.get("channel_reject_any", [])
        mood_keywords: list[str] = lang_conf.get("music_mood_keywords", {}).get(mood, [])
        query_terms = set(re.findall(r'\w+', query.lower())) if query else set()

        best_video = None
        candidates = []

        for q in queries:
            if not q:
                continue
            try:
                results = _search_youtube(q, videoCategoryId="10")  # 10 = Music
            except Exception as exc:
                print(f"[youtube-music] search failed for {q!r}: {exc}", flush=True)
                continue

            for idx, item in enumerate(results):
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
                score = sum(3 if kw in title else (1 if kw in desc else 0)
                            for kw in mood_keywords)

                # Boost if user's specific query keywords match
                for term in query_terms:
                    if len(term) > 3:
                        score += 5 if term in title else (2 if term in desc else 0)
                
                # YouTube relevance bonus (earlier results preferred on tie)
                score += (20 - idx) * 0.1

                candidates.append((score, item))

            # Stop after first query that yields a matched result.
            if candidates:
                # Sort by score so we only pick from the best mood matches.
                # Randomizing among the top 5 prevents repeating the same song
                # for different users who select the same mood.
                candidates.sort(key=lambda x: x[0], reverse=True)
                top_candidates = candidates[:5]
                best_video = random.choice(top_candidates)[1]
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
    if language == "my":
        mood_terms = lang_conf.get("music_mood_keywords", {}).get(mood, [])
        queries = [
            query if _has_myanmar(query) else "",
            f"{query} တရားဟောချက်" if _has_myanmar(query) else "",
            *[f"{anchor} {term} တရားဟောချက်" for term in mood_terms[:2]],
            f"{anchor} တရားဟောချက်",
            f"{anchor} တရားဟော",
            f"{anchor} နုတ်ကပတ်တော်",
            f"{anchor} သွန်သင်ချက်",
        ]
    elif language == "td":
        # Lead with native Tedim preaching vocabulary so results are in-language
        # before falling back to English sermon terms.
        queries = [
            query if _has_tedim_identity(query) else "",
            f"{query} thugenna" if _has_tedim_identity(query) else "",
            f"{anchor} thugenna",          # zomi christian thugenna
            f"zomi thugenna {mood}",
            f"zomi thu gen",
            f"tedim christian sermon",
            f"{anchor} sermon {mood}",
            f"{anchor} sermon",
        ]
    else:
        queries = [
            query,
            f"{query} sermon" if query else "",
            f"{anchor} sermon {mood}",
            f"{anchor} preaching {mood}",
            f"{anchor} message of hope",
            f"{anchor} sermon",
        ]
    queries = list(dict.fromkeys(q for q in queries if q))

    require: list[str] = lang_conf.get("sermon_title_require_any", [])
    reject: list[str] = lang_conf.get("sermon_title_reject_any", [])
    channel_reject: list[str] = lang_conf.get("channel_reject_any", [])
    search_params = {}
    if language == "my":
        search_params = {"relevanceLanguage": "my", "regionCode": "MM"}

    mood_keywords: list[str] = lang_conf.get("music_mood_keywords", {}).get(mood, [])
    query_terms = set(re.findall(r'\w+', query.lower())) if query else set()

    for q in queries:
        if not q:
            continue
        try:
            results = _search_youtube(q, **search_params)
        except Exception as exc:
            print(f"[sermon] query {q!r} failed: {exc}", flush=True)
            continue

        candidates = []

        for idx, item in enumerate(results):
            video_id = item.get("id", {}).get("videoId")
            if not video_id or video_id in excluded:
                continue

            snippet = item.get("snippet", {})
            title = (snippet.get("title") or "").lower()
            channel = (snippet.get("channelTitle") or "").lower()
            desc = (snippet.get("description") or "").lower()

            # Burmese-mode sermons must show Myanmar script in the title.
            # Channel/description text is too weak: an English sermon from a
            # Burmese channel can still have localized metadata elsewhere.
            if language == "my" and not _has_myanmar(title):
                continue

            # Tedim uses Latin script so we can't gate on a Unicode range.
            # Instead require at least one Zomi/Tedim community word in the
            # title — this blocks English sermons that happen to match the
            # generic "sermon"/"preaching"/"message" require terms.
            if language == "td" and not _has_tedim_identity(title):
                continue

            # Gate 1: title must contain a preaching indicator (whole-word match).
            if require and not any(_keyword_hit(kw, title) for kw in require):
                continue
            # Gate 2: title must NOT contain a music/choir/event keyword (whole-word).
            if any(_keyword_hit(kw, title) for kw in reject):
                continue
            # Gate 3: reject off-topic channels.
            if any(kw in channel for kw in channel_reject):
                continue

            score = sum(3 if kw in title else (1 if kw in desc else 0) for kw in mood_keywords)
            
            for term in query_terms:
                if len(term) > 3:
                    score += 5 if term in title else (2 if term in desc else 0)
            
            score += (20 - idx) * 0.1

            candidates.append((score, item))
        
        if candidates:
            # Randomize among the top 5 highly-scored sermons to add variety 
            # across different users with the same mood.
            candidates.sort(key=lambda x: x[0], reverse=True)
            best_video = random.choice(candidates[:5])[1]
            return {"video_id": best_video["id"]["videoId"], "title": best_video["snippet"]["title"]}

    raise RuntimeError(f"No suitable YouTube sermon found for mood {mood!r} in language {language!r}")
