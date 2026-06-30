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

After the hardcoded gates, the admin-curated content filter applies as a final
firewall: blocklist keywords reject a result, but an allowlist keyword overrides
the blocklist and keeps the result (allow wins over block). Filter terms are
matched against the title, channel name, channel id, and the video/channel URLs
— so an admin can block a whole channel (by name, id, or URL) or a specific URL.
Both lists are scope-aware (music / sermon) and admin-editable.

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

# Admin-curated content filter, fetched from the backend /config endpoint.
CHURCH_API_URL = os.getenv("CHURCH_API_URL", "https://api.aivirtual.church/api").rstrip("/")
_ADMIN_FILTER_TTL = 300  # seconds; matches the "changes take effect within 5 minutes" promise
_admin_filter_cache: dict[str, tuple[float, list[str]]] = {}

# Phase 2b.1: how strongly to PREFER captioned sermons. Added on top of the
# relevance score — large enough to float a captioned result into the top-5 pick
# over similar-relevance uncaptioned ones, small enough that a clearly more relevant
# uncaptioned sermon can still win. A preference, never a hard filter.
_CAPTION_SCORE_BONUS = 5.0


def is_enabled() -> bool:
    return bool(YOUTUBE_API_KEY)


def _admin_filter_keywords(cache_key: str, config_key: str, fallback_key: str | None = None) -> list[str]:
    """Fetch an admin-curated keyword list from the backend /config endpoint.

    Cached for a few minutes so every candidate scan doesn't hit the API. Fails
    open (returns []) so a backend hiccup never blocks service generation — the
    hardcoded per-language gates still apply.
    """
    cached = _admin_filter_cache.get(cache_key)
    now = time.time()
    if cached and now - cached[0] < _ADMIN_FILTER_TTL:
        return cached[1]

    keywords: list[str] = []
    try:
        resp = requests.get(f"{CHURCH_API_URL}/config", timeout=8)
        resp.raise_for_status()
        data = resp.json()
        raw = data.get(config_key)
        if not raw and fallback_key:
            raw = data.get(fallback_key)
        raw = raw or []
        keywords = [str(k).strip().lower() for k in raw if str(k).strip()]
    except Exception as exc:  # noqa: BLE001 — fail open, keep stale value if any
        print(f"[content-filter] fetch failed for {cache_key!r}: {exc}", flush=True)
        if cached:
            return cached[1]

    _admin_filter_cache[cache_key] = (now, keywords)
    return keywords


def _admin_reject_keywords(scope: str) -> list[str]:
    """Admin blocklist for a search scope ('music'|'sermon')."""
    key = "content_filter_sermon" if scope == "sermon" else "content_filter_music"
    return _admin_filter_keywords(key, key, fallback_key="content_filter_keywords")


def _admin_allow_keywords(scope: str) -> list[str]:
    """Admin allowlist for a search scope — trusted terms that override the blocklist.

    Firewall model: every result is subject to the blocklist, but a match here
    forces the result to be kept (allow wins over block).
    """
    key = "content_filter_allow_sermon" if scope == "sermon" else "content_filter_allow_music"
    return _admin_filter_keywords(key, key)


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
            "pasian", "topa", "zeisu", "krist", "phatna", "lungdam", "labu",
        ],
        # cartoon, animation, drama, film — "Zomi Song 2015" was a cartoon thumbnail.
        "music_title_reject_any": [
            "reaction", "interview", "podcast", "vlog", "album", "teaser",
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
            "We Worship Zomi", "FEMC Worship", "ZACC Worship", "Khai Pi", "Cin Bawi",
        ],
    },

    # ── config-driven native-first languages (see _NATIVE_QUERY_LANGS) ──────────
    # Each entry drives both the YouTube queries (query_anchor + *_query_terms) and
    # the result gates (*_title_require_any / *_title_reject_any). Non-Latin titles
    # are additionally screened by script (_native_language_ok). Add a language by
    # adding an entry here and to _NATIVE_QUERY_LANGS — no other code change.
    "fr": {
        "query_anchor": "chrétien",
        "relevance_language": "fr", "region_code": "FR",
        "sermon_query_terms": ["prédication", "sermon", "enseignement biblique"],
        "sermon_title_require_any": ["prédication", "sermon", "message", "pasteur", "parole de dieu", "enseignement"],
        "sermon_title_reject_any": ["chorale", "concert", "chanson", "louange", "musique", "clip", "choir", "song", "music"],
        "worship_query_terms": ["chant de louange chrétien", "adoration chrétienne", "louange"],
        "music_title_require_any": ["louange", "adoration", "chrétien", "chrétienne", "gospel", "worship"],
        "music_title_reject_any": ["reaction", "interview", "podcast", "film", "bande annonce"],
        "channel_reject_any": ["movie clips", "gaming", "news", "actualités", "politique"],
    },
    "de": {
        "query_anchor": "christlich",
        "relevance_language": "de", "region_code": "DE",
        "sermon_query_terms": ["predigt", "botschaft", "bibelstunde"],
        "sermon_title_require_any": ["predigt", "botschaft", "pastor", "wort gottes", "andacht", "auslegung"],
        "sermon_title_reject_any": ["chor", "konzert", "lied", "lobpreis", "musik", "choir", "song", "music"],
        "worship_query_terms": ["christliches lobpreislied", "anbetung", "lobpreis"],
        "music_title_require_any": ["lobpreis", "anbetung", "christlich", "christliche", "gospel", "worship"],
        "music_title_reject_any": ["reaction", "interview", "podcast", "film", "trailer"],
        "channel_reject_any": ["movie clips", "gaming", "nachrichten", "news", "politik"],
    },
    "es": {
        "query_anchor": "cristiano",
        "relevance_language": "es", "region_code": "ES",
        "sermon_query_terms": ["predicación", "sermón", "enseñanza bíblica"],
        "sermon_title_require_any": ["predicación", "predica", "sermón", "mensaje", "pastor", "palabra de dios", "enseñanza"],
        "sermon_title_reject_any": ["coro", "concierto", "canción", "alabanza", "música", "choir", "song", "music"],
        "worship_query_terms": ["canción cristiana de adoración", "alabanza cristiana", "adoración"],
        "music_title_require_any": ["alabanza", "adoración", "cristiano", "cristiana", "gospel", "worship"],
        "music_title_reject_any": ["reaction", "interview", "podcast", "película", "tráiler"],
        "channel_reject_any": ["movie clips", "gaming", "noticias", "news", "política"],
    },
    "ja": {
        "query_anchor": "キリスト教",
        "relevance_language": "ja", "region_code": "JP",
        "sermon_query_terms": ["説教", "礼拝メッセージ", "聖書"],
        "sermon_title_require_any": ["説教", "メッセージ", "牧師", "礼拝", "御言葉"],
        "sermon_title_reject_any": ["賛美歌", "聖歌隊", "コンサート", "音楽", "concert", "choir", "music"],
        "worship_query_terms": ["賛美歌", "ワーシップ", "礼拝賛美"],
        "music_title_require_any": ["賛美", "礼拝", "ワーシップ", "ゴスペル", "キリスト", "worship"],
        "music_title_reject_any": ["reaction", "interview", "podcast", "映画", "予告"],
        "channel_reject_any": ["movie", "gaming", "news", "ニュース"],
    },
    "zh-CN": {
        "query_anchor": "基督教",
        "relevance_language": "zh", "region_code": "CN",
        "sermon_query_terms": ["讲道", "证道", "主日信息"],
        "sermon_title_require_any": ["讲道", "证道", "信息", "牧师", "主日"],
        "sermon_title_reject_any": ["诗歌", "诗班", "音乐会", "敬拜赞美", "音乐", "concert", "choir", "music"],
        "worship_query_terms": ["敬拜赞美诗歌", "基督教敬拜", "赞美诗歌"],
        "music_title_require_any": ["敬拜", "赞美", "诗歌", "基督", "worship"],
        "music_title_reject_any": ["reaction", "interview", "podcast", "电影", "预告"],
        "channel_reject_any": ["movie", "gaming", "news", "新闻"],
    },
    "ko": {
        "query_anchor": "기독교",
        "relevance_language": "ko", "region_code": "KR",
        "sermon_query_terms": ["설교", "주일설교", "말씀"],
        "sermon_title_require_any": ["설교", "말씀", "목사", "주일", "예배 메시지"],
        "sermon_title_reject_any": ["찬양", "성가대", "콘서트", "음악", "워십", "concert", "choir", "music"],
        "worship_query_terms": ["기독교 예배 찬양", "한국어 찬양", "워십"],
        "music_title_require_any": ["찬양", "예배", "워십", "ccm", "기독교", "worship"],
        "music_title_reject_any": ["reaction", "interview", "podcast", "영화", "예고"],
        "channel_reject_any": ["movie", "gaming", "news", "뉴스"],
    },
    "hi": {
        "query_anchor": "मसीही",
        "relevance_language": "hi", "region_code": "IN",
        "sermon_query_terms": ["उपदेश", "प्रवचन", "वचन"],
        "sermon_title_require_any": ["उपदेश", "प्रवचन", "संदेश", "वचन", "पास्टर"],
        "sermon_title_reject_any": ["भजन", "गीत", "संगीत", "गायन", "concert", "choir", "music", "song"],
        "worship_query_terms": ["मसीही आराधना गीत", "स्तुति", "आराधना"],
        "music_title_require_any": ["आराधना", "स्तुति", "मसीही", "भजन", "worship"],
        "music_title_reject_any": ["reaction", "interview", "podcast", "movie", "trailer"],
        "channel_reject_any": ["telugu", "tamil", "kannada", "malayalam", "movie", "film", "news"],
    },
    "ta": {
        "query_anchor": "கிறிஸ்தவ",
        "relevance_language": "ta", "region_code": "IN",
        "sermon_query_terms": ["பிரசங்கம்", "செய்தி", "வசனம்"],
        "sermon_title_require_any": ["பிரசங்கம்", "செய்தி", "வசனம்", "போதகர்"],
        "sermon_title_reject_any": ["பாடல்", "கீர்த்தனை", "இசை", "பாடகர்", "concert", "choir", "music", "song"],
        "worship_query_terms": ["தமிழ் கிறிஸ்தவ ஆராதனை பாடல்", "ஆராதனை", "துதி"],
        "music_title_require_any": ["ஆராதனை", "துதி", "கிறிஸ்தவ", "worship"],
        "music_title_reject_any": ["reaction", "interview", "podcast", "movie", "trailer"],
        "channel_reject_any": ["telugu", "kannada", "malayalam", "hindi", "movie", "film", "news"],
    },
    "th": {
        "query_anchor": "คริสเตียน",
        "relevance_language": "th", "region_code": "TH",
        "sermon_query_terms": ["คำเทศนา", "เทศนา", "พระวจนะ"],
        "sermon_title_require_any": ["คำเทศนา", "เทศนา", "ข้อความ", "ศิษยาภิบาล", "พระวจนะ"],
        "sermon_title_reject_any": ["เพลง", "นมัสการ", "ดนตรี", "คอนเสิร์ต", "concert", "choir", "music", "song"],
        "worship_query_terms": ["เพลงนมัสการคริสเตียน", "สรรเสริญ", "นมัสการ"],
        "music_title_require_any": ["นมัสการ", "สรรเสริญ", "คริสเตียน", "worship"],
        "music_title_reject_any": ["reaction", "interview", "podcast", "หนัง", "ตัวอย่าง"],
        "channel_reject_any": ["movie", "gaming", "news", "ข่าว"],
    },
    "ar": {
        "query_anchor": "مسيحي",
        "relevance_language": "ar", "region_code": "EG",
        "sermon_query_terms": ["عظة", "وعظ", "كلمة الله"],
        "sermon_title_require_any": ["عظة", "وعظ", "رسالة", "كلمة الله", "راعي"],
        "sermon_title_reject_any": ["ترنيمة", "ترانيم", "جوقة", "حفل", "موسيقى", "concert", "choir", "music", "song"],
        "worship_query_terms": ["ترانيم عبادة مسيحية", "تسبيح", "عبادة"],
        "music_title_require_any": ["تسبيح", "عبادة", "ترنيمة", "ترانيم", "مسيحي", "worship"],
        "music_title_reject_any": ["reaction", "interview", "podcast", "فيلم", "إعلان"],
        "channel_reject_any": ["movie", "gaming", "news", "أخبار"],
    },
    "he": {
        "query_anchor": "נוצרי",
        "relevance_language": "he", "region_code": "IL",
        "sermon_query_terms": ["דרשה", "מסר", "דבר אלוהים"],
        "sermon_title_require_any": ["דרשה", "מסר", "דבר אלוהים", "כומר", "מסר רוחני"],
        "sermon_title_reject_any": ["מזמור", "מקהלה", "קונצרט", "מוזיקה", "concert", "choir", "music", "song"],
        "worship_query_terms": ["שירי הלל נוצריים", "שבח", "פולחן"],
        "music_title_require_any": ["הלל", "שבח", "פולחן", "משיחי", "נוצרי", "worship"],
        "music_title_reject_any": ["reaction", "interview", "podcast", "סרט", "טריילר"],
        "channel_reject_any": ["movie", "gaming", "news", "חדשות"],
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


# Languages handled by the config-driven native branch below (en/my/td keep their
# bespoke, well-tested logic). Adding one here + an entry in _LANG_CONFIG is all a
# new native-first language needs.
_NATIVE_QUERY_LANGS = frozenset({
    "fr", "de", "es", "ja", "zh-CN", "ko", "hi", "ta", "th", "ar", "he",
})

# Unicode ranges that signal a particular major script. Mirrors the proven table
# in MusicRecommendationService::titleLanguageScriptOk() (worship radio) so the
# service pipeline and the radio agree on what "wrong language" looks like.
_SCRIPTS: dict[str, str] = {
    "myanmar":    "\u1000-\u109F",
    "devanagari": "\u0900-\u097F",
    "bengali":    "\u0980-\u09FF",
    "tamil":      "\u0B80-\u0BFF",
    "telugu":     "\u0C00-\u0C7F",
    "thai":       "\u0E00-\u0E7F",
    "arabic":     "\u0600-\u06FF",
    "hebrew":     "\u0590-\u05FF",
    "cyrillic":   "\u0400-\u04FF",
    "kana":       "\u3040-\u30FF",
    "han":        "\u3400-\u9FFF",
    "hangul":     "\uAC00-\uD7AF",
}

# Scripts each language legitimately uses besides Latin. A language not listed is
# Latin-script (en/fr/de/es/td): Latin is allowed everywhere, so it is screened by
# rejecting foreign scripts only \u2014 native query terms + relevanceLanguage do the
# rest of the language separation.
_LANG_SCRIPTS: dict[str, list[str]] = {
    "my": ["myanmar"],
    "ja": ["kana", "han"],
    "zh-CN": ["han"],
    "ko": ["hangul", "han"],
    "hi": ["devanagari"],
    "ta": ["tamil"],
    "th": ["thai"],
    "ar": ["arabic"],
    "he": ["hebrew"],
}


def _has_native_script(text: str, language: str) -> bool:
    """True if a non-Latin language's own script appears in text (Latin langs: always)."""
    allowed = _LANG_SCRIPTS.get(language, [])
    if not allowed:
        return True
    ranges = "".join(_SCRIPTS[name] for name in allowed)
    return re.search("[" + ranges + "]", text or "") is not None


def _script_ok(text: str, language: str) -> bool:
    """Reject text carrying a major script the language does not use (Latin allowed)."""
    allowed = _LANG_SCRIPTS.get(language, [])
    for name, ranges in _SCRIPTS.items():
        if name in allowed:
            continue
        if re.search("[" + ranges + "]", text or ""):
            return False
    return True


def _native_language_ok(text: str, language: str) -> bool:
    """Combined gate for the config-driven languages: the title must carry the
    language's own script (non-Latin langs) and must NOT carry a foreign script."""
    return _has_native_script(text, language) and _script_ok(text, language)


# Words that unambiguously identify a video as Zomi/Tedim content.
# We check these against the lowercased title before applying the generic
# sermon-indicator gate, mirroring the Myanmar-script check for Burmese.
_TEDIM_IDENTITY_WORDS = frozenset([
    "zomi", "tedim", "zolai",
    "thugenna", "thugen",  # sermon/preaching in Tedim
    "thu gen",             # two-word form of preaching
])


def _has_tedim_identity(text: str, is_music: bool = False) -> bool:
    """Return True when text contains a Zomi/Tedim community or language marker."""
    lower = (text or "").lower()
    if any(word in lower for word in _TEDIM_IDENTITY_WORDS):
        return True
    if is_music:
        for word in ["pasian", "topa", "zeisu", "krist", "phatna", "lungdam", "labu", "zacc", "gupna", "itna"]:
            if _wb(word, lower):
                return True
        for name in ["khai pi", "cin bawi", "thang taung", "phillip ruth", "zomi worship", "femc worship", "we worship zomi"]:
            if name in lower:
                return True
    return False


def _keyword_hit(kw: str, text: str) -> bool:
    """Match Latin keywords on word boundaries; non-Latin keywords by substring."""
    return _wb(kw, text) if kw.isascii() else kw in text


def _url_meta(video_id: str, channel_id: str) -> str:
    """Lowercased channel id + canonical URLs an admin filter term can target,
    so a pasted channel/video URL or channel id blocks the whole result."""
    parts = [channel_id, f"https://www.youtube.com/watch?v={video_id}"]
    if channel_id:
        parts.append(f"https://www.youtube.com/channel/{channel_id}")
    return " ".join(p for p in parts if p).lower()


def _admin_hit(kw: str, title: str, channel: str, meta: str) -> bool:
    """Admin filter match: word-boundary on title/channel name, substring on the
    channel-id/URL metadata (channel and URL blocking)."""
    return _keyword_hit(kw, title) or _keyword_hit(kw, channel) or (kw in meta)


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

        if self.language in _NATIVE_QUERY_LANGS:
            # Search in the worshipper's language using native worship vocabulary
            # so a Korean/Japanese/… service surfaces in-language worship first.
            worship_terms = lang_conf.get("worship_query_terms", ["worship song"])
            queries = [f"{anchor} {term}" for term in worship_terms]
            queries.append(f"{anchor} {worship_terms[0]} {mood}")
            if query:
                queries.insert(0, query)
        else:
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
        admin_reject: list[str] = _admin_reject_keywords("music")
        admin_allow: list[str] = _admin_allow_keywords("music")
        mood_keywords: list[str] = lang_conf.get("music_mood_keywords", {}).get(mood, [])
        query_terms = set(re.findall(r'\w+', query.lower())) if query else set()

        # Bias YouTube toward the worshipper's language for the config-driven
        # languages; the script gate + require terms still decide acceptance.
        music_search_params: dict = {}
        if lang_conf.get("relevance_language"):
            music_search_params["relevanceLanguage"] = lang_conf["relevance_language"]
            if lang_conf.get("region_code"):
                music_search_params["regionCode"] = lang_conf["region_code"]

        best_video = None
        candidates = []

        for q in queries:
            if not q:
                continue
            try:
                results = _search_youtube(q, videoCategoryId="10", **music_search_params)  # 10 = Music
            except Exception as exc:
                print(f"[youtube-music] search failed for {q!r}: {exc}", flush=True)
                continue

            for idx, item in enumerate(results):
                snippet = item.get("snippet", {})
                title = (snippet.get("title") or "").lower()
                channel = (snippet.get("channelTitle") or "").lower()
                desc = (snippet.get("description") or "").lower()
                meta = _url_meta(item.get("id", {}).get("videoId") or "", snippet.get("channelId") or "")

                # Burmese-mode music must show Myanmar script in the title.
                if self.language == "my" and not _has_myanmar(title):
                    continue

                # Tedim mode music must have a Zomi/Tedim identity word in the title or channel.
                if self.language == "td" and not (_has_tedim_identity(title, is_music=True) or _has_tedim_identity(channel, is_music=True)):
                    continue

                # Config-driven languages: native script required (non-Latin),
                # foreign scripts rejected — keeps a Korean slot Korean, etc.
                if self.language in _NATIVE_QUERY_LANGS and not _native_language_ok(title, self.language):
                    continue

                # Gate 1: must contain at least one Christian/worship term.
                if music_require and not any(kw in title for kw in music_require):
                    continue
                # Gate 2: reject non-worship content.
                if any(kw in title for kw in music_reject):
                    continue
                # Gate 3: reject off-topic channels.
                if any(kw in channel for kw in channel_reject):
                    continue
                # Gate 4: admin-curated content filter (title or channel).
                # Firewall model — an allowlist hit overrides the blocklist (allow wins).
                allowed = any(_admin_hit(kw, title, channel, meta) for kw in admin_allow)
                if not allowed and any(_admin_hit(kw, title, channel, meta) for kw in admin_reject):
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

def _videos_with_captions(video_ids: list[str]) -> set[str]:
    """Phase 2b.1 — return the subset of `video_ids` that carry a caption track
    (`contentDetails.caption == "true"`). One cheap `videos.list` call (1 quota unit
    for up to 50 ids). Fails OPEN (returns empty set) so caption-aware ranking can
    only ever *prefer* captioned videos — it never blocks, filters, or drops a result.
    """
    ids = [v for v in dict.fromkeys(video_ids) if v]
    if not ids or not is_enabled():
        return set()
    try:
        resp = requests.get(
            "https://www.googleapis.com/youtube/v3/videos",
            params={"key": YOUTUBE_API_KEY, "part": "contentDetails", "id": ",".join(ids[:50])},
            timeout=15,
        )
        resp.raise_for_status()
        return {
            item.get("id")
            for item in resp.json().get("items", [])
            if (item.get("contentDetails") or {}).get("caption") == "true"
        }
    except Exception as exc:  # noqa: BLE001 — fail open; ranking degrades to 2a behavior
        print(f"[sermon] caption lookup failed: {exc}", flush=True)
        return set()


def find_sermon_video(
    mood: str,
    query: str,
    language: str,
    excluded_ids: list[str] | None = None,
) -> dict:
    """Find a Christian preaching video for the worshipper's theme (synchronous).

    Called from sync Celery tasks — do NOT make this async.

    Pure discovery: returns {"found": True, "video_id": str, "title": str} on a
    match, or {"found": False, "reason": "no_match"} when nothing in this language
    passes the gates. It never decides fallback policy (English fallback, regional
    ladders) — that is the orchestrator's job, which calls this once per language.
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
    elif language in _NATIVE_QUERY_LANGS:
        # Build the whole query ladder from the language's native preaching
        # vocabulary so a Korean/Japanese/… service searches in its own language
        # first — never English. The LLM-provided `query` (often English) goes
        # last; the native-script result gate below drops anything off-language.
        sermon_terms = lang_conf.get("sermon_query_terms", ["sermon"])
        queries = [f"{anchor} {term}" for term in sermon_terms]
        queries.append(f"{anchor} {sermon_terms[0]} {mood}")
        if query:
            queries.append(query)
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
    admin_reject: list[str] = _admin_reject_keywords("sermon")
    admin_allow: list[str] = _admin_allow_keywords("sermon")
    search_params = {}
    if language == "my":
        search_params = {"relevanceLanguage": "my", "regionCode": "MM"}
    elif lang_conf.get("relevance_language"):
        # Bias YouTube toward the worshipper's language; the result gates still
        # decide what is accepted.
        search_params = {"relevanceLanguage": lang_conf["relevance_language"]}
        if lang_conf.get("region_code"):
            search_params["regionCode"] = lang_conf["region_code"]

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
            meta = _url_meta(video_id, snippet.get("channelId") or "")

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

            # Config-driven languages: a non-Latin language's title must carry
            # its own script, and no title may carry a foreign major script.
            if language in _NATIVE_QUERY_LANGS and not _native_language_ok(title, language):
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
            # Gate 4: admin-curated content filter (title or channel).
            # Firewall model — an allowlist hit overrides the blocklist (allow wins).
            allowed = any(_admin_hit(kw, title, channel, meta) for kw in admin_allow)
            if not allowed and any(_admin_hit(kw, title, channel, meta) for kw in admin_reject):
                continue

            score = sum(3 if kw in title else (1 if kw in desc else 0) for kw in mood_keywords)
            
            for term in query_terms:
                if len(term) > 3:
                    score += 5 if term in title else (2 if term in desc else 0)
            
            score += (20 - idx) * 0.1

            candidates.append((score, item))
        
        if candidates:
            # Phase 2b.1 — PREFER sermons that carry a caption track (a translatable
            # transcript for the future subtitle step). This is a score bonus, NOT a
            # filter: uncaptioned sermons still qualify, so the native-first behavior
            # validated in 2a never regresses. Fails open if the lookup is unavailable.
            captioned = _videos_with_captions([it["id"]["videoId"] for _, it in candidates])
            if captioned:
                candidates = [
                    (score + (_CAPTION_SCORE_BONUS if it["id"]["videoId"] in captioned else 0.0), it)
                    for score, it in candidates
                ]
            # Randomize among the top 5 highly-scored sermons to add variety
            # across different users with the same mood.
            candidates.sort(key=lambda x: x[0], reverse=True)
            best_video = random.choice(candidates[:5])[1]
            return {
                "found": True,
                "video_id": best_video["id"]["videoId"],
                "title": best_video["snippet"]["title"],
            }

    return {"found": False, "reason": "no_match"}
