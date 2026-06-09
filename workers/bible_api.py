"""Resolve a scripture reference to full text from a local public-domain Bible.

The ESV API needs publisher approval we don't have yet, so we serve verse text from
a bundled copy of the Berean Standard Bible (BSB) — public domain, modern English,
complete, no key, no network. Data file vendored from dalsuum/bible's `3034.json`.

Switch translations with BIBLE_DATA_FILE once you hold rights to another text; any
file from that repo shares this schema (book -> chapter -> verse -> {"text": ...}).
"""

from __future__ import annotations

import functools
import json
import os
import re

_DEFAULT_DATA = os.path.join(os.path.dirname(os.path.abspath(__file__)), "data", "bsb.json")
DATA_FILE = os.getenv("BIBLE_DATA_FILE", _DEFAULT_DATA)

# Book-name variants the model emits that don't match the data file's `name` field.
_ALIASES = {
    "psalm": "psalms",
    "song of solomon": "song",
    "song of songs": "song",
    "canticles": "song",
    "qoheleth": "ecclesiastes",
    "revelations": "revelation",
    "apocalypse": "revelation",
}

# Leading book ordinals: "1 John", "I John", "First John" all mean book ordinal 1.
_ORDINALS = {"i": "1", "ii": "2", "iii": "3", "first": "1", "second": "2", "third": "3"}

_REF_RE = re.compile(
    r"^\s*(?P<book>(?:[1-3]|i{1,3}|first|second|third)\s+)?(?P<name>[a-z][a-z .]*?)\s+"
    r"(?P<chapter>\d+)(?::(?P<v1>\d+)(?:\s*[-–—]\s*(?P<v2>\d+))?)?\s*$",
    re.IGNORECASE,
)


def _norm(s: str) -> str:
    """Lowercase, drop periods, collapse whitespace — for case/spacing-insensitive keys."""
    return re.sub(r"\s+", " ", s.replace(".", "").strip().lower())


@functools.lru_cache(maxsize=1)
def _bible() -> dict:
    with open(DATA_FILE, encoding="utf-8") as fh:
        return json.load(fh)


@functools.lru_cache(maxsize=1)
def _book_index() -> dict[str, str]:
    """Map every normalized name/shortname/abbr in the data file to its book number.

    Built from the file itself so it tracks that file's own numbering (the WEB data
    includes the Apocrypha, so book numbers are not the canonical 1-66)."""
    index: dict[str, str] = {}
    for num, book in _bible()["book"].items():
        info = book.get("info", {})
        keys = [info.get("name", ""), info.get("shortname", ""), *info.get("abbr", [])]
        for key in keys:
            if key:
                index[_norm(key)] = num
    return index


def _parse(reference: str) -> tuple[str, str, int, int] | None:
    """Reference string -> (book_number, chapter, verse_start, verse_end), or None."""
    m = _REF_RE.match(reference)
    if not m:
        return None

    name = _norm(m.group("name"))
    ordinal = m.group("book")
    if ordinal:
        ordinal = _ORDINALS.get(_norm(ordinal), _norm(ordinal))
        name = f"{ordinal} {name}"
    name = _ALIASES.get(name, name)

    book_num = _book_index().get(name)
    if not book_num:
        return None

    chapter = m.group("chapter")
    v1 = int(m.group("v1")) if m.group("v1") else None
    v2 = int(m.group("v2")) if m.group("v2") else v1
    return book_num, chapter, v1, v2


def resolve(reference: str) -> str:
    """Return the verse text for a reference like 'Psalm 23:1-4' or 'John 3:16'.

    Whole-chapter references ('Psalm 23') return the full chapter. Returns "" if the
    reference can't be parsed or isn't present in the data file — the caller degrades
    to showing the bare reference rather than aborting the service."""
    parsed = _parse(reference)
    if not parsed:
        return ""

    book_num, chapter, v1, v2 = parsed
    verses = _bible()["book"].get(book_num, {}).get("chapter", {}).get(chapter, {}).get("verse", {})
    if not verses:
        return ""

    if v1 is None:  # whole chapter
        wanted = sorted(verses, key=int)
    else:
        wanted = [str(n) for n in range(v1, v2 + 1) if str(n) in verses]

    text = " ".join(verses[n]["text"].strip() for n in wanted if verses.get(n, {}).get("text"))
    return text.strip()
