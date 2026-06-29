"""Resolve a scripture reference to full text from local Bible translations.

Translations are bundled public-domain or freely redistributable data (no key,
no network):

  'en'  — Berean Standard Bible (BSB), the existing behavior. Data file from
         dalsuum/bible's `3034.json`, vendored as data/bsb.json (BIBLE_DATA_FILE).
  'kjv' — Authorized (King James) Version, public domain. Second English edition,
         vendored as data/kjv.json (BIBLE_DATA_FILE_KJV); built by
         tools/build_kjv_bible.py from getbible.net's `kjv` module (full 66-book
         canon, canonical 1-66 numbering). English book names, so it indexes the
         same canonical English book list as the non-English files.
  'my' — သမ္မာကျမ်း (Judson, 1835), public domain. Vendored from dalsuum/bible's
         `judson1835.json` as data/judson1835.json (BIBLE_DATA_FILE_MY).
  'td' — Lai Siangtho (Tedim, 1932), public domain. Vendored from dalsuum/bible's
         `tedim1932.json` as data/tedim1932.json (BIBLE_DATA_FILE_TD).
  'he' — Hebrew Tanakh (Westminster Leningrad Codex), public domain. Vendored
         from dalsuum/bible as data/wlc.json (BIBLE_DATA_FILE_HE). Old Testament
         only (books 1-39); right-to-left script.

  Chin/Zo language Bibles from the Bible Society of Myanmar, all vendored from
  dalsuum/bible in the same shared schema (Latin script, full 66-book canon):
  'cfm' Falam (falam1973.json), 'cnh' Hakha (hakha1920.json),
  'lus' Mizo (mizo1917.json), 'pck' Paite (paite1971.json),
  'csy' Sizang (sizang1932.json),
  'mrh' Mara (mara2011.json), 'hlt' Matu (matu2009.json).

  World-language Bibles:
  'ar' Arabic Van Dyck, 'de' Luther 1912, 'es' Reina Valera 1909,
       'fr' Ostervald 1877, 'ja' Colloquial Japanese 1955,
       'ko' Korean Revised Version, 'zh-CN' Chinese Union Simplified
       (public domain);
  'hi' Hindi IRV 2019, 'ta' Tamil IRV 2019 (Creative Commons BY-SA 4.0);
  'th' Thai KJV 2003 (Creative Commons BY-NC-ND 4.0).

  Copyrighted drop-in slots (not bundled; provide licensed same-schema JSON):
  'ja-jcb' Japanese Contemporary Bible / リビングバイブル (JCB),
  'zh-CN-ccb' Chinese Contemporary Bible / 当代译本 (CCB).

The LLM always emits ENGLISH references ("John 3:16") regardless of service
language — references are part of the worker contract, not the worshipper-facing
text. The non-English files' book names are in their own language (ရှင်ယောဟန် /
Johan), so they can't index an English reference by themselves. Every file uses
canonical 1-66 numbering (Genesis=1 … Revelation=66), so for those languages we
parse the reference against a vendored canonical English book index
(data/books_en.json, from dalsuum/bible's category.json) and look the verses up
in the translation file by that number. Adding another compatible translation is
one _LANG_FILES entry once the dataset source/license is verified.

Shared schema: book -> chapter -> verse -> {"text": ...}.
"""

from __future__ import annotations

import functools
import json
import os
import re
import unicodedata

_DATA_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "data")
DATA_FILE = os.getenv("BIBLE_DATA_FILE", os.path.join(_DATA_DIR, "bsb.json"))
BOOKS_EN_FILE = os.path.join(_DATA_DIR, "books_en.json")

# Translations resolved against the canonical English book index (canonical 1-66
# numbering), rather than against the default BSB file's own numbering.
# 'kjv' is a second English edition — its book names are English too, but it uses
# the same canonical index path so adding it stays "one line" (see _book_index).
# 'he' is the Hebrew Tanakh (Westminster Leningrad Codex) — Old Testament only,
# so its file holds books 1-39; the reader pads the New Testament (40-66) as
# greyed/unavailable entries (see list_books).
_LANG_FILES = {
    "kjv": os.getenv("BIBLE_DATA_FILE_KJV", os.path.join(_DATA_DIR, "kjv.json")),
    "my": os.getenv("BIBLE_DATA_FILE_MY", os.path.join(_DATA_DIR, "judson1835.json")),
    "td": os.getenv("BIBLE_DATA_FILE_TD", os.path.join(_DATA_DIR, "tedim1932.json")),
    "he": os.getenv("BIBLE_DATA_FILE_HE", os.path.join(_DATA_DIR, "wlc.json")),
    # Chin/Zo language Bibles from the Bible Society of Myanmar (dalsuum/bible),
    # all in the shared schema with canonical 1-66 numbering. Latin script.
    "cfm": os.getenv("BIBLE_DATA_FILE_CFM", os.path.join(_DATA_DIR, "falam1973.json")),
    "cnh": os.getenv("BIBLE_DATA_FILE_CNH", os.path.join(_DATA_DIR, "hakha1920.json")),
    "lus": os.getenv("BIBLE_DATA_FILE_LUS", os.path.join(_DATA_DIR, "mizo1917.json")),
    "pck": os.getenv("BIBLE_DATA_FILE_PCK", os.path.join(_DATA_DIR, "paite1971.json")),
    "csy": os.getenv("BIBLE_DATA_FILE_CSY", os.path.join(_DATA_DIR, "sizang1932.json")),
    "mrh": os.getenv("BIBLE_DATA_FILE_MRH", os.path.join(_DATA_DIR, "mara2011.json")),
    "hlt": os.getenv("BIBLE_DATA_FILE_HLT", os.path.join(_DATA_DIR, "matu2009.json")),
    # World-language Bibles, same schema + canonical 1-66 numbering. Only
    # translations with verified redistribution terms are vendored; see
    # docs/BIBLE_TRANSLATION_SOURCES.md for source/license details.
    "ar": os.getenv("BIBLE_DATA_FILE_AR", os.path.join(_DATA_DIR, "arabic_vandyke.json")),
    "de": os.getenv("BIBLE_DATA_FILE_DE", os.path.join(_DATA_DIR, "luther1912.json")),
    "es": os.getenv("BIBLE_DATA_FILE_ES", os.path.join(_DATA_DIR, "spanish_rv1909.json")),
    "fr": os.getenv("BIBLE_DATA_FILE_FR", os.path.join(_DATA_DIR, "ostervald1877.json")),
    "hi": os.getenv("BIBLE_DATA_FILE_HI", os.path.join(_DATA_DIR, "hindi_irv2019.json")),
    "ja": os.getenv("BIBLE_DATA_FILE_JA", os.path.join(_DATA_DIR, "japanese_colloquial1955.json")),
    "ko": os.getenv("BIBLE_DATA_FILE_KO", os.path.join(_DATA_DIR, "korean_krv.json")),
    "ta": os.getenv("BIBLE_DATA_FILE_TA", os.path.join(_DATA_DIR, "tamil_irv2019.json")),
    "th": os.getenv("BIBLE_DATA_FILE_TH", os.path.join(_DATA_DIR, "thai_kjv.json")),
    "zh-CN": os.getenv("BIBLE_DATA_FILE_ZH_CN", os.path.join(_DATA_DIR, "chinese_union_simplified.json")),
    # Copyrighted drop-in slots. These are intentionally not vendored and are
    # advertised by languages() only when a licensed same-schema JSON file is
    # installed at the configured path.
    "ja-jcb": os.getenv("BIBLE_DATA_FILE_JA_JCB", os.path.join(_DATA_DIR, "japanese_jcb.json")),
    "zh-CN-ccb": os.getenv("BIBLE_DATA_FILE_ZH_CN_CCB", os.path.join(_DATA_DIR, "chinese_ccb_simplified.json")),
}

# Translations whose source file has correct, canonically-positioned verse
# content but unreliable native book-name metadata (e.g. Matu's names are shifted
# by its appended deuterocanon). For these the table of contents falls back to
# the canonical English book names rather than showing the file's wrong labels.
_BOOK_NAMES_FROM_CANON = {"hlt"}

# Translations covering only part of the canon: book number -> last book present.
# Their reader table of contents is padded out to the full 66 with the missing
# books shown greyed (available=False). Hebrew Tanakh = Old Testament (1-39).
_PARTIAL_CANON = {"he": 39}

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
    """Normalize a name/query for case-, accent- and spacing-insensitive matching.

    Unicode-aware so non-English book names and queries compare reliably:
      - NFKD decompose, then drop combining marks U+0300–U+036F only. That folds
        Latin accents (Genèse → genese, Café → cafe) WITHOUT touching the
        combining marks that carry meaning in Tamil/Devanagari/Thai or the
        optional Arabic harakat / Hebrew niqqud (all outside that range).
      - casefold() for robust, language-aware lowercasing.
      - drop periods, collapse whitespace, NFC recompose.
    """
    s = unicodedata.normalize("NFKD", s)
    s = "".join(c for c in s if not (0x300 <= ord(c) <= 0x36F))
    s = unicodedata.normalize("NFC", s).casefold()
    return re.sub(r"\s+", " ", s.replace(".", "").strip())


@functools.lru_cache(maxsize=32)
def _bible(lang: str = "en") -> dict:
    path = _LANG_FILES.get(lang, DATA_FILE)
    # A translation file may not be vendored yet (e.g. Hebrew WLC is committed to
    # dalsuum/bible separately). Degrade to an empty canon rather than 500-ing so
    # the reader still renders its table of contents (padded/greyed) and books.
    if not os.path.exists(path):
        return {"book": {}}
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)


@functools.lru_cache(maxsize=32)
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


# --- Browsable online-Bible helpers --------------------------------------
# The functions above resolve a single English reference for the service
# pipeline. The ones below expose the whole vendored text for a reader UI:
# list books, count chapters, fetch a chapter's verses — in any served language.

_LANGS = (
    "kjv", "en", "he", "my",
    "cfm", "cnh", "mrh", "hlt", "lus", "pck", "csy", "td",
    # World-language Bibles (only those whose data file is actually vendored).
    "ar", "de", "es", "fr", "hi", "ja", "ko", "ta", "th", "zh-CN",
)

_DROP_IN_LANGS = ("ja-jcb", "zh-CN-ccb")


def _drop_in_available(lang: str) -> bool:
    return os.path.exists(_LANG_FILES.get(lang, ""))


def languages() -> list[str]:
    """Translations available to the reader, in display order."""
    return list(_LANGS) + [lang for lang in _DROP_IN_LANGS if _drop_in_available(lang)]


# Canonical Bible knowledge model (platform ontology). Additive + structural;
# keyed by stable canonical id ('genesis'…'revelation'). IDs are infrastructure,
# display names are localization — see docs/BIBLE_KNOWLEDGE_MODEL_INVARIANTS.md.
# This is read ALONGSIDE the existing book index/alias search, which is unchanged.
_BOOKS_META_FILE = os.path.join(_DATA_DIR, "books_meta.json")


@functools.lru_cache(maxsize=1)
def books_meta() -> dict:
    """The ontology keyed by canonical book id. Returns {} if the file isn't
    vendored, so reader/search behavior never depends on it being present."""
    if not os.path.exists(_BOOKS_META_FILE):
        return {}
    with open(_BOOKS_META_FILE, encoding="utf-8") as fh:
        return json.load(fh).get("books", {})


def book_meta(book_id: str) -> dict | None:
    """Ontology entry for a canonical book id (e.g. 'genesis'), or None if unknown."""
    return books_meta().get(book_id)


@functools.lru_cache(maxsize=32)
def list_books(lang: str = "en") -> list[dict]:
    """Every book in `lang`: [{'num', 'name', 'chapters', 'available'}], canonical order.

    `name` is the book's heading in the translation's own language so the
    reader's table of contents reads natively (Genesis / ကမ္ဘာဦးကျမ်း / Piancilna).
    `available` is False for books a partial-canon translation doesn't cover
    (e.g. the New Testament under the Hebrew Tanakh): those are padded in with
    their canonical English name so the reader can grey them out rather than
    hide the rest of the canon.
    """
    books = _bible(lang).get("book", {})
    # Canonical English names per book number — used to label name-unreliable
    # translations (Matu) and, for every non-English translation, to attach
    # English search `aliases` so readers can find a book by its English name or
    # abbreviation ("Genesis"/"Gen"/"Ge") as well as its native heading.
    canon_names: dict[str, dict] = {}
    if lang in _BOOK_NAMES_FROM_CANON or lang in _LANG_FILES:
        with open(BOOKS_EN_FILE, encoding="utf-8") as fh:
            canon_names = json.load(fh)
    out: list[dict] = []
    for num in sorted(books, key=int):
        # Canonical-indexed translations use the 1-66 Protestant canon; some
        # source files append deuterocanonical books (e.g. Matu has 67-72). Drop
        # those so the table of contents matches the reference contract. 'en'
        # keeps its own numbering (it may legitimately include the Apocrypha).
        if lang in _LANG_FILES and int(num) > 66:
            continue
        book = books[num]
        if lang in _BOOK_NAMES_FROM_CANON:
            name = canon_names.get(num, {}).get("name", "") or f"Book {num}"
        else:
            name = book.get("info", {}).get("name", "") or f"Book {num}"
        ci = canon_names.get(num, {})
        aliases = list(dict.fromkeys(
            a for a in [ci.get("name", ""), ci.get("shortname", ""),
                        *ci.get("abbr", [])] if a))
        out.append({
            "num": int(num),
            "name": name,
            "aliases": aliases,
            "chapters": len(book.get("chapter", {})),
            "available": True,
        })

    # Pad a partial-canon translation out to the full 66 with greyed placeholders
    # so the table of contents still shows the whole Bible. Missing books take
    # their canonical English name (the translation has none of its own for them).
    if lang in _PARTIAL_CANON:
        present = {b["num"] for b in out}
        with open(BOOKS_EN_FILE, encoding="utf-8") as fh:
            canon = json.load(fh)
        for num_str, info in canon.items():
            num = int(num_str)
            if num in present:
                continue
            out.append({
                "num": num,
                "name": info.get("name", "") or f"Book {num}",
                "chapters": 0,
                "available": False,
            })
        out.sort(key=lambda b: b["num"])
    return out


def chapter(lang: str, book: int | str, chapter_num: int | str) -> dict:
    """A single chapter: {'book', 'name', 'chapter', 'verses': [{'num','text'}]}.

    Returns empty `verses` when the book or chapter isn't present rather than
    raising, so the caller can render a graceful "not found" state.
    """
    book = str(book)
    chapter_num = str(chapter_num)
    b = _bible(lang).get("book", {}).get(book, {})
    if lang in _BOOK_NAMES_FROM_CANON:
        with open(BOOKS_EN_FILE, encoding="utf-8") as fh:
            name = json.load(fh).get(book, {}).get("name", "")
    else:
        name = b.get("info", {}).get("name", "")
    verse_map = b.get("chapter", {}).get(chapter_num, {}).get("verse", {})
    verses = [
        {"num": int(n), "text": (verse_map[n].get("text") or "").strip()}
        for n in sorted(verse_map, key=int)
    ]
    return {"book": int(book), "name": name, "chapter": int(chapter_num), "verses": verses}


def resolve(reference: str, lang: str = "en") -> str:
    """Return the verse text for a reference like 'Psalm 23:1-4' or 'John 3:16'.

    `lang` selects the translation ('en' BSB, 'kjv' King James Version, 'my'
    Judson 1835, 'td' Tedim 1932); the reference
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
