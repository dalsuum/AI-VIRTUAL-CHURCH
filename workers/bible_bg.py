"""AI background music for the online Bible reader.

When the admin enables "AI background mode", a soft instrumental loop is played
behind the chapter narration. The track is chosen from two cheap, low-cardinality
dimensions so the cache stays tiny and the same audio is reused for everyone:

  theme  - a coarse mood inferred from the chapter text (comfort, praise,
           lament, hope, peace, wisdom). Keyword heuristic, no LLM call.
  tod    - the *reader's* local time of day (morning/afternoon/evening/night),
           passed from the browser since server time != reader time.

The track is stored under a deterministic, language-independent key
``bible-bg/{theme}_{tod}.mp3`` (instrumental — no words), so existence-check +
presign is all that's needed; no DB registry or webhook. Generation is offloaded
to the Celery ai:music worker (``tasks.generate_bible_bg``) so it never blocks
the Bible API process or the narration path. First reader to hit an uncached
bucket gets no music (or the admin's static fallback); once generated it is
cached permanently.
"""

from __future__ import annotations

import storage

# Coarse moods → MusicGen style fragments. Kept small on purpose: theme x tod is
# the whole cache matrix (6 x 4 = 24 instrumental tracks, shared across languages).
_THEME_PROMPTS: dict[str, str] = {
    "comfort": "gentle comforting instrumental, soft piano, warm strings, consoling",
    "praise":  "uplifting worshipful instrumental, bright piano, soaring strings, major key",
    "lament":  "tender sorrowful instrumental, slow piano, mournful cello, minor key",
    "hope":    "hopeful uplifting instrumental, acoustic guitar, building strings, light",
    "peace":   "calm peaceful instrumental, soft pads, ambient piano, still and restful",
    "wisdom":  "contemplative reflective instrumental, sparse piano, thoughtful, unhurried",
}
_DEFAULT_THEME = "peace"

# Reader-local time of day → tonal colouring layered onto the theme prompt.
_TOD_TONES: dict[str, str] = {
    "morning":   "bright gentle sunrise tone, fresh and awakening",
    "afternoon": "steady warm daytime tone, settled",
    "evening":   "soft reflective dusk tone, mellow",
    "night":     "quiet hushed nighttime tone, very soft and ambient",
}
_DEFAULT_TOD = "afternoon"

# Lower-cased keyword → theme. First match (scanned in order) wins; scoring keeps
# it deterministic so the same chapter always maps to the same cached track.
_THEME_KEYWORDS: dict[str, tuple[str, ...]] = {
    "lament":  ("weep", "mourn", "sorrow", "grief", "trouble", "afflict", "anguish",
                "distress", "lament", "tears", "despair", "forsaken"),
    "praise":  ("praise", "rejoice", "glory", "hallelujah", "exalt", "magnify",
                "sing", "worship", "bless the lord", "thanksgiving"),
    "comfort": ("comfort", "fear not", "do not be afraid", "refuge", "shepherd",
                "rest", "shelter", "near to", "heal", "tender"),
    "hope":    ("hope", "promise", "deliver", "salvation", "redeem", "restore",
                "everlasting", "faithful", "trust", "await"),
    "wisdom":  ("wisdom", "wise", "knowledge", "understanding", "proverb",
                "instruct", "discern", "counsel", "prudent"),
    "peace":   ("peace", "still", "quiet", "calm", "abide", "dwell"),
}

ENGINES = {"musicgen", "local_ai"}
_DEFAULT_ENGINE = "musicgen"

# Shorter than worship tracks: a loop only needs ~20 s before it repeats.
_BG_MAX_TOKENS = 1000


def normalize_tod(tod: str) -> str:
    return tod if tod in _TOD_TONES else _DEFAULT_TOD


def tod_from_hour(hour: int) -> str:
    """Map a 0-23 local hour to a time-of-day bucket."""
    try:
        h = int(hour) % 24
    except (TypeError, ValueError):
        return _DEFAULT_TOD
    if 5 <= h < 12:
        return "morning"
    if 12 <= h < 17:
        return "afternoon"
    if 17 <= h < 21:
        return "evening"
    return "night"


def classify_theme(text: str) -> str:
    """Infer a coarse mood from chapter text via a deterministic keyword vote."""
    if not text:
        return _DEFAULT_THEME
    low = text.lower()
    best_theme, best_score = _DEFAULT_THEME, 0
    # Stable order so ties always resolve the same way.
    for theme in ("comfort", "praise", "lament", "hope", "peace", "wisdom"):
        score = sum(low.count(kw) for kw in _THEME_KEYWORDS.get(theme, ()))
        if score > best_score:
            best_theme, best_score = theme, score
    return best_theme


def _key_base(theme: str, tod: str) -> str:
    theme = theme if theme in _THEME_PROMPTS else _DEFAULT_THEME
    return f"bible-bg/{theme}_{normalize_tod(tod)}"


def cache_key(theme: str, tod: str, ext: str = "mp3") -> str:
    return f"{_key_base(theme, tod)}.{ext}"


def build_prompt(theme: str, tod: str) -> str:
    style = _THEME_PROMPTS.get(theme, _THEME_PROMPTS[_DEFAULT_THEME])
    tone = _TOD_TONES.get(normalize_tod(tod), _TOD_TONES[_DEFAULT_TOD])
    return f"{style}, {tone}, soft background worship music, no vocals, loopable"


def _resolve_existing_key(theme: str, tod: str) -> str | None:
    """Return the stored key (mp3 preferred, wav fallback) if generated, else None."""
    base = _key_base(theme, tod)
    for ext in ("mp3", "wav"):
        key = f"{base}.{ext}"
        if storage.exists(key):
            return key
    return None


def existing_url(theme: str, tod: str) -> str | None:
    """Presigned URL for the cached track, or None if it hasn't been generated."""
    key = _resolve_existing_key(theme, tod)
    return storage.presign(key, expires=6 * 3600) if key else None


def generate_track(theme: str, tod: str, engine: str = _DEFAULT_ENGINE) -> str | None:
    """Generate and store the instrumental loop for (theme, tod). Idempotent.

    Returns the presigned URL on success, or None if another worker already
    produced it. Concurrency is bounded by the shared MusicGen Redis lock so a
    2 GB box never runs two generations at once.
    """
    import os

    from strategies import musicgen_strategy as mg

    theme = theme if theme in _THEME_PROMPTS else _DEFAULT_THEME
    tod = normalize_tod(tod)

    # Cheap pre-check: skip the whole pipeline if it already exists.
    existing = existing_url(theme, tod)
    if existing:
        return existing

    # local_ai prefers GPU; musicgen keeps the default device resolution.
    if engine == "local_ai":
        os.environ.setdefault("MUSICGEN_DEVICE", "auto")

    client = mg._redis_lock()
    if not mg._acquire_lock(client, timeout=900):
        raise RuntimeError("[bible-bg] could not acquire MusicGen lock within 15 min")
    try:
        # Re-check under the lock: a concurrent task may have just finished.
        existing = existing_url(theme, tod)
        if existing:
            return existing
        audio_bytes, ext, content_type = mg.generate_instrumental(
            build_prompt(theme, tod), max_tokens=_BG_MAX_TOKENS
        )
        key = cache_key(theme, tod, ext)
        storage.upload_bytes(key, audio_bytes, content_type)
    finally:
        mg._release_lock(client)

    return storage.presign(key, expires=6 * 3600)
