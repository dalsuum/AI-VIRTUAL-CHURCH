"""Scripture engine (Phase 8) — a thin, immutable contract over bible_api.py.

The orchestrator and prompt engine treat scripture as RESOLVED, IMMUTABLE data the
model RECEIVES — never text the model produces or fetches. The LLM only cites
references; this module resolves them to a frozen VerseObject carrying the licensed
exact text from the local public-domain corpus (no outbound network in v1).

Two entry points, both built on bible_api's existing parser:
  resolve_ref(ref, translation)  -> VerseObject          (single reference)
  detect_refs(text, translation) -> list[VerseObject]    (scan free prose)

Nothing here mutates verse text, translation, or canonical_id: VerseObject is a
frozen dataclass, so a downstream bug cannot silently corrupt scripture.
"""

from __future__ import annotations

import functools
import os
import re
import sys
from dataclasses import asdict, dataclass

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import bible_api  # noqa: E402  (sibling module in workers/)

# Requested translations we don't carry locally fall back to this code (BSB).
_FALLBACK_TRANSLATION = "en"

# Cap verses materialized for a single object so "Psalm 119" can't explode cost.
_MAX_RANGE_VERSES = 60

# Cheap pre-filter: skip the full parser unless the text has a letter immediately
# followed (within a couple of chars) by a digit — the shape of "John 3", "1 Cor 5".
_PREFILTER_RE = re.compile(r"[A-Za-z][A-Za-z .]{0,20}\d")


@dataclass(frozen=True)
class VerseObject:
    """Immutable resolved scripture. `resolved=False` ⇒ outside corpus coverage
    (text=""); the orchestrator surfaces the gap rather than inventing text."""

    ref: str
    canonical_id: str          # BBBCCCVVV (zero-padded); whole chapter ⇒ VVV=000
    canonical_end: str
    translation: str           # the translation actually used
    book_name: str             # localized display name
    book_num: int
    chapter: int
    verse_start: int | None    # None ⇒ whole-chapter reference
    verse_end: int | None
    text: str
    resolved: bool
    translation_fallback: bool  # True ⇒ requested translation unavailable, used fallback
    truncated: bool = False     # True ⇒ range exceeded _MAX_RANGE_VERSES

    def to_dict(self) -> dict:
        return asdict(self)


def canonical_id(book_num: int, chapter: int, verse: int) -> str:
    """Stable, sortable, dedupable key: 3-digit book, chapter, verse."""
    return f"{book_num:03d}{chapter:03d}{verse:03d}"


def _resolve_translation(translation: str) -> tuple[str, bool]:
    """Map a requested translation to one we actually carry. Unknown codes (NIV/
    NLT, not vendored in v1) degrade to the fallback — never fabricate."""
    requested = (translation or "").strip()
    langs = bible_api.languages()
    if requested in langs:
        return requested, False
    code = requested.lower()
    canonical = {lang.lower(): lang for lang in langs}.get(code)
    if canonical:
        return canonical, False
    return _FALLBACK_TRANSLATION, True


@functools.lru_cache(maxsize=2048)
def resolve_ref(ref: str, translation: str = "en") -> VerseObject:
    """Resolve one reference to an immutable VerseObject. Cached on (ref, translation);
    the local corpus is static so caching is always safe. Text is built directly from
    the chapter's verses so single verses, ranges, and whole chapters are all capped
    at _MAX_RANGE_VERSES (long ranges set truncated=True)."""
    used, fallback = _resolve_translation(translation)
    ref = (ref or "").strip()

    parsed = bible_api._parse(ref, used)  # (book_num_s, chapter_s, v1, v2) | None
    if not parsed:
        return VerseObject(
            ref=ref, canonical_id="", canonical_end="", translation=used,
            book_name="", book_num=0, chapter=0, verse_start=None, verse_end=None,
            text="", resolved=False, translation_fallback=fallback,
        )

    book_num_s, chapter_s, v1, v2 = parsed
    book_num, chapter = int(book_num_s), int(chapter_s)

    ch = bible_api.chapter(used, book_num_s, chapter_s)
    all_verses = ch.get("verses", [])
    if v1 is None:                       # whole chapter
        wanted = all_verses
    else:
        hi = v2 if v2 else v1
        wanted = [v for v in all_verses if v1 <= v["num"] <= hi]

    truncated = len(wanted) > _MAX_RANGE_VERSES
    wanted = wanted[:_MAX_RANGE_VERSES]
    text = " ".join(v["text"] for v in wanted if v.get("text")).strip()

    verse_start = v1
    verse_end = wanted[-1]["num"] if (v1 is not None and wanted) else (v2 if v2 else v1)

    cid_verse = verse_start if verse_start is not None else 0
    cid_end_verse = verse_end if verse_end is not None else 0

    return VerseObject(
        ref=ref,
        canonical_id=canonical_id(book_num, chapter, cid_verse),
        canonical_end=canonical_id(book_num, chapter, cid_end_verse),
        translation=used,
        book_name=ch.get("name", "") or "",
        book_num=book_num,
        chapter=chapter,
        verse_start=verse_start,
        verse_end=verse_end,
        text=text,
        resolved=bool(text),
        translation_fallback=fallback,
        truncated=truncated,
    )


@functools.lru_cache(maxsize=16)
def _detect_re() -> re.Pattern:
    """Build a scanning regex from the recognized English book tokens (names,
    shortnames, abbreviations, aliases), longest-first so 'song of songs' wins over
    'song'. Anchored matches are validated by resolve_ref afterwards."""
    tokens = set(bible_api._book_index("en").keys()) | set(bible_api._ALIASES.keys())
    tokens = sorted((t for t in tokens if t), key=len, reverse=True)
    alt = "|".join(re.escape(t) for t in tokens)
    ordinal = r"(?:[1-3]|i{1,3}|first|second|third)\s+"
    return re.compile(
        rf"\b(?:{ordinal})?(?:{alt})\s+\d+(?::\d+(?:\s*[-–—]\s*\d+)?)?",
        re.IGNORECASE,
    )


def detect_refs(text: str, translation: str = "en", max_refs: int = 8) -> list[VerseObject]:
    """Scan free prose for scripture references, returning resolved VerseObjects
    (deduped by canonical_id, bounded by max_refs). Cheap pre-filter skips the full
    scan on text with no 'word+number' shape, so non-scripture turns stay light."""
    if not text or not _PREFILTER_RE.search(text):
        return []

    out: list[VerseObject] = []
    seen: set[str] = set()
    for m in _detect_re().finditer(text):
        if len(out) >= max_refs:
            break
        vo = resolve_ref(m.group(0).strip(), translation)
        if not vo.resolved or not vo.canonical_id or vo.canonical_id in seen:
            continue
        seen.add(vo.canonical_id)
        out.append(vo)
    return out
