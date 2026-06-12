"""Resolve a scripture reference to full text from a local public-domain Bible.

Two languages are served, both from bundled public-domain data (no key, no network):

  'en' — Berean Standard Bible (BSB), the existing behavior. Data file from
         dalsuum/bible's `3034.json`, vendored as data/bsb.json (BIBLE_DATA_FILE).
  'my' — သမ္မာကျမ်း (Judson, 1835), public domain. Vendored from dalsuum/bible's
         `judson1835.json` as data/judson1835.json (BIBLE_DATA_FILE_MY).
  'td' — Lai Siangtho (Tedim, 1932), public domain. Vendored from dalsuum/bible's
         `tedim1932.json` as data/tedim1932.json (BIBLE_DATA_FILE_TD).

The LLM always emits ENGLISH references ("John 3:16") regardless of service
language — references are part of the worker contract, not the worshipper-facing
text. The non-English files' book names are in their own language (ရှင်ယောဟန် /
Johan), so they can't index an English reference by themselves. Every file uses
canonical 1-66 numbering (Genesis=1 … Revelation=66), so for those languages we
parse the reference against a vendored canonical English book index
(data/books_en.json, from dalsuum/bible's category.json) and look the verses up
in the translation file by that number. Adding another dalsuum/bible translation
is one line in _LANG_FILES.

Schema shared by every dalsuum/bible file: book -> chapter -> verse -> {"text": ...}.
"""

from __future__ import annotations

import functools
import json
import os
import re

_DATA_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "data")
DATA_FILE = os.getenv("BIBLE_DATA_FILE", os.path.join(_DATA_DIR, "bsb.json"))
BOOKS_EN_FILE = os.path.join(_DATA_DIR, "books_en.json")

# Non-English translations (all dalsuum/bible schema, canonical 1-66 numbering).
_LANG_FILES = {
    "my": os.getenv("BIBLE_DATA_FILE_MY", os.path.join(_DATA_DIR, "judson1835.json")),
    "td": os.getenv("BIBLE_DATA_FILE_TD", os.path.join(_DATA_DIR, "tedim1932.json")),
}

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


@functools.lru_cache(maxsize=4)
def _bible(lang: str = "en") -> dict:
    path = _LANG_FILES.get(lang, DATA_FILE)
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)


@functools.lru_cache(maxsize=4)
def _book_index(lang: str = "en") -> dict[str, str]:
    """Map every normalized English name/shortname/abbr to a book number.

    'en': built from the data file itself so it tracks that file's own numbering
    (some files include the Apocrypha, so numbers aren't always canonical 1-66).
    Other languages: their book names aren't English — index the vendored
    canonical English book list instead; every _LANG_FILES translation uses
    canonical 1-66 numbering, so the canonical number IS that file's book key.
    """
    index: dict[str, str] = {}
    if lang in _LANG_FILES:
        with open(BOOKS_EN_FILE, encoding="utf-8") as fh:
            books = json.load(fh)
        for num, info in books.items():
            for key in [info.get("name", ""), info.get("shortname", ""), *info.get("abbr", [])]:
                if key:
                    index[_norm(key)] = num
                    # The model freely writes "Psalms"/"Proverb"-style variants;
                    # register a naive plural alongside each singular name.
                    if key and not key.endswith("s"):
                        index[_norm(key) + "s"] = num
        return index
    for num, book in _bible("en")["book"].items():
        info = book.get("info", {})
        for key in [info.get("name", ""), info.get("shortname", ""), *info.get("abbr", [])]:
            if key:
                index[_norm(key)] = num
    return index


def _parse(reference: str, lang: str = "en") -> tuple[str, str, int | None, int | None] | None:
    """English reference string -> (book_number, chapter, verse_start, verse_end)."""
    m = _REF_RE.match(reference)
    if not m:
        return None

    name = _norm(m.group("name"))
    ordinal = m.group("book")
    if ordinal:
        ordinal = _ORDINALS.get(_norm(ordinal), _norm(ordinal))
        name = f"{ordinal} {name}"
    # Try the aliased form first, then the raw name — the alias table targets the
    # BSB file's naming ("psalms"), the canonical index uses singular ("Psalm").
    index = _book_index(lang)
    book_num = index.get(_ALIASES.get(name, name)) or index.get(name)
    if not book_num:
        return None

    chapter = m.group("chapter")
    v1 = int(m.group("v1")) if m.group("v1") else None
    v2 = int(m.group("v2")) if m.group("v2") else v1
    return book_num, chapter, v1, v2


def book_title(reference: str, lang: str = "en") -> str:
    """The display name of the referenced book in the target language.

    Used to caption non-English scripture with the translation's own book name
    ('John 3:16' -> 'ရှင်ယောဟန်ခရစ်ဝင်' for 'my', 'Johan 3:16' for 'td') so the
    worshipper-facing heading matches the service language. Returns "" when the
    reference can't be parsed."""
    parsed = _parse(reference, lang)
    if not parsed:
        return ""
    book_num, chapter, v1, v2 = parsed
    name = _bible(lang)["book"].get(book_num, {}).get("info", {}).get("name", "")
    if not name:
        return ""
    suffix = f" {chapter}" + (f":{v1}" + (f"-{v2}" if v2 and v2 != v1 else "") if v1 else "")
    return f"{name}{suffix}"


def resolve(reference: str, lang: str = "en") -> str:
    """Return the verse text for a reference like 'Psalm 23:1-4' or 'John 3:16'.

    `lang` selects the translation ('en' BSB, 'my' Judson 1835, 'td' Tedim
    1932); the reference
    itself is always English. Whole-chapter references ('Psalm 23') return the
    full chapter. Returns "" if the reference can't be parsed or isn't present —
    the caller degrades to showing the bare reference rather than aborting."""
    parsed = _parse(reference, lang)
    if not parsed:
        return ""

    book_num, chapter, v1, v2 = parsed
    verses = _bible(lang)["book"].get(book_num, {}).get("chapter", {}).get(chapter, {}).get("verse", {})
    if not verses:
        return ""

    if v1 is None:  # whole chapter
        wanted = sorted(verses, key=int)
    else:
        wanted = [str(n) for n in range(v1, v2 + 1) if str(n) in verses]

    text = " ".join(verses[n]["text"].strip() for n in wanted if verses.get(n, {}).get("text"))
    return text.strip()
