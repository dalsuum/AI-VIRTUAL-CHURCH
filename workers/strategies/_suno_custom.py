"""Shared helper: sing a hymn's verses through Suno customMode.

Both non-English hymn strategies (Myanmar, Tedim) compose their singing the
same way — submit the hymn's verbatim verses as customMode lyrics so the
vocals sing the real hymn, poll, download — so the machinery lives here once.
SunoStrategy supplies the gateway plumbing (headers, polling, mirror-aware
download); only the submit body differs from prompt-mode generation.
"""

from __future__ import annotations

import os
import re

import requests


# Suno customMode caps prompt (lyrics) length; stay safely under and cut on a
# verse boundary so the sung text never ends mid-line.
MAX_LYRICS = int(os.getenv("SUNO_CUSTOM_MAX_LYRICS", "2800"))


def trim_on_verse(lyrics: str, limit: int = MAX_LYRICS) -> str:
    if len(lyrics) <= limit:
        return lyrics
    cut = lyrics[:limit]
    for sep in ("\n\n", "\n"):
        idx = cut.rfind(sep)
        if idx > limit // 2:
            return cut[:idx].rstrip()
    return cut.rstrip()


# Burmese native section labels → Suno structural metatags (never sung)
_MY_SECTION_TAGS: tuple[tuple[str, str], ...] = (
    (r"(?m)^\s*အပိုဒ်\s*[၁1]\s*$",          "[Verse 1]"),
    (r"(?m)^\s*အပိုဒ်\s*[၂2]\s*$",          "[Verse 2]"),
    (r"(?m)^\s*အပိုဒ်\s*[၃3]\s*$",          "[Verse 3]"),
    (r"(?m)^\s*သံပြိုင်\s*$",               "[Chorus]"),
    # ထပ်ဆိုရန် = "Repeat" / Chorus marker used in classic hymnal layout
    (r"(?m)^\s*ထပ်ဆိုရန်[။\.\:\)]*\s*$",   "[Chorus]"),
    (r"(?m)^\s*တံတားဆက်\s*$",               "[Bridge]"),
    (r"(?m)^\s*နိဂုံး\s*$",                 "[Outro]"),
)

# Burmese digits ၁-၉ mapped to ASCII for verse-tag generation.
_BURMESE_DIGIT_MAP = str.maketrans("၁၂၃၄၅၆၇၈၉", "123456789")

# Plain English section labels (no brackets) → Suno structural metatags
_EN_SECTION_TAGS: tuple[tuple[str, str], ...] = (
    (r"(?im)^\s*verse\s*1\s*[:\-]?\s*$",        "[Verse 1]"),
    (r"(?im)^\s*verse\s*2\s*[:\-]?\s*$",        "[Verse 2]"),
    (r"(?im)^\s*verse\s*3\s*[:\-]?\s*$",        "[Verse 3]"),
    (r"(?im)^\s*verse\s*[:\-]?\s*$",            "[Verse 1]"),
    (r"(?im)^\s*(chorus|refrain)\s*[:\-]?\s*$", "[Chorus]"),
    (r"(?im)^\s*pre-?chorus\s*[:\-]?\s*$",      "[Pre-Chorus]"),
    (r"(?im)^\s*bridge\s*[:\-]?\s*$",           "[Bridge]"),
    (r"(?im)^\s*outro\s*[:\-]?\s*$",            "[Outro]"),
    (r"(?im)^\s*intro\s*[:\-]?\s*$",            "[Intro]"),
)

_SENSITIVE_REPLACEMENTS: tuple[tuple[str, str], ...] = (
    (r"\bkill\s+myself\b", "find new hope"),
    (r"\bwant\s+to\s+die\b", "feel so low"),
    (r"\bsuicid(?:e|al)\b", "deep despair"),
    (r"\bself[- ]?harm\b", "hidden pain"),
    (r"\boverdose\b", "heavy burden"),
    (r"\bmurder\b", "brokenness"),
    (r"\bviolence\b", "sorrow"),
    (r"\bdrugs?\b", "bondage"),
    (r"\bsex\b", "love"),
)


def sanitize_style(style: str) -> str:
    text = (style or "").strip()
    text = re.sub(r"`{1,3}.*?`{1,3}", "", text)
    text = re.sub(r"[\[\]{}<>]", " ", text)
    text = re.sub(r"\s+", " ", text).strip()
    lowered = text.lower()
    if any(k in lowered for k in ("suicid", "self-harm", "kill myself", "want to die", "murder", "violent")):
        text = "Modern contemporary Christian worship with hopeful and healing tone, warm choir, acoustic band"
    return text[:480]


def _verse_number_sub(m: re.Match) -> str:
    """'၁ lyric text' → '[Verse 1]\\nlyric text'; '၁' alone → '[Verse 1]'."""
    n = m.group(1).translate(_BURMESE_DIGIT_MAP)
    rest = m.group(2).strip() if m.lastindex and m.group(2) else ""
    return f"[Verse {n}]\n{rest}" if rest else f"[Verse {n}]"


def sanitize_lyrics(lyrics: str) -> str:
    text = (lyrics or "").replace("\r\n", "\n")
    text = re.sub(r"[`*_#]", "", text)
    # Convert native-language and plain-English section labels to Suno structural
    # metatags ([Verse 1], [Chorus], etc.). Suno reads these as instructions and
    # never sings them — they must be kept, not stripped.
    for pattern, tag in _MY_SECTION_TAGS:
        text = re.sub(pattern, tag, text)
    for pattern, tag in _EN_SECTION_TAGS:
        text = re.sub(pattern, tag, text)
    # Burmese numeral verse prefixes: "၁ lyric text" → "[Verse 1]\nlyric text";
    # standalone "၁" → "[Verse 1]". Classic hymnals prefix each verse this way
    # instead of using a separate section-header line.
    text = re.sub(r"(?m)^\s*([၁-၉])\s+(.+)$", _verse_number_sub, text)
    text = re.sub(r"(?m)^\s*([၁-၉])\s*$",     _verse_number_sub, text)
    for pattern, repl in _SENSITIVE_REPLACEMENTS:
        text = re.sub(pattern, repl, text, flags=re.IGNORECASE)
    text = re.sub(r"\n{3,}", "\n\n", text)
    text = re.sub(r"[ \t]+", " ", text)
    text = re.sub(r" ?\n ?", "\n", text)
    return trim_on_verse(text.strip())


def safe_payload_variants(style: str, lyrics: str) -> list[tuple[str, str]]:
    style_0 = sanitize_style(style)
    lyrics_0 = sanitize_lyrics(lyrics)
    style_1 = "Modern contemporary Christian worship, hopeful and peaceful, warm choir and acoustic band"
    lyrics_1 = sanitize_lyrics(re.sub(r"(?im)^\s*[^\n]{0,120}\n", "", lyrics_0, count=1)) or lyrics_0
    lines = [ln.strip() for ln in lyrics_1.split("\n") if ln.strip()]
    lyrics_2 = sanitize_lyrics("\n".join(lines[:8]) or lyrics_1)
    variants = [(style_0, lyrics_0), (style_1, lyrics_1), (style_1, lyrics_2)]
    out: list[tuple[str, str]] = []
    seen: set[tuple[str, str]] = set()
    for pair in variants:
        if pair not in seen:
            seen.add(pair)
            out.append(pair)
    return out


def is_sensitive_error(exc: Exception) -> bool:
    return "SENSITIVE_WORD_ERROR" in str(exc)


def sing(*, title: str, lyrics: str, style: str) -> bytes:
    """Submit verses to Suno customMode and return the finished MP3 bytes.

    Raises on any provider failure — callers degrade the same way every music
    strategy does (generate_music skips the segment rather than crashing).
    """
    from .suno_strategy import SunoStrategy

    suno = SunoStrategy()  # raises KeyError early if SUNO_API_KEY is missing
    attempts = safe_payload_variants(style, lyrics)
    last_exc: Exception | None = None

    for idx, (style_try, lyrics_try) in enumerate(attempts, start=1):
        try:
            resp = requests.post(
                f"{suno.BASE_URL}/generate",
                headers=suno._headers,
                json={
                    "prompt": lyrics_try,
                    "style": style_try,
                    "title": title[:80],
                    "customMode": True,
                    "instrumental": False,
                    "model": suno.MODEL,
                    "callBackUrl": suno.CALLBACK_URL,
                },
                timeout=30,
            )
            resp.raise_for_status()
            body = resp.json()
            if body.get("code") != 200:
                raise RuntimeError(f"Suno submit rejected: {body.get('msg')!r} ({body.get('code')})")
            track = suno._poll(body["data"]["taskId"])
            return suno._download(track)
        except Exception as exc:
            last_exc = exc
            if not is_sensitive_error(exc) or idx >= len(attempts):
                raise
            print(f"[music] Suno custom moderation retry {idx}/{len(attempts)}: {exc}", flush=True)

    raise RuntimeError(f"Suno custom generation failed after moderation retries: {last_exc}")
