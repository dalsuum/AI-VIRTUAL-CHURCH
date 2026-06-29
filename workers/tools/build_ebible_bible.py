"""Build selected eBible USFM translations into the local Bible JSON schema.

Usage:

    python workers/tools/build_ebible_bible.py ar zh-CN es th

The output matches workers/bible_api.py's expected schema:
book -> chapter -> verse -> {"text": "..."} with canonical 1-66 book numbers.
USFM notes, cross-references, and formatting markers are stripped so reader,
search, and AI consumers receive verse text only.
"""

from __future__ import annotations

import argparse
import io
import json
import os
import re
import urllib.request
import zipfile
from dataclasses import dataclass

DATA_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "data")

_HEADERS = {"User-Agent": "ai-church-bible-seeder/1.0"}
_BOOK_ORDER = [
    "GEN", "EXO", "LEV", "NUM", "DEU", "JOS", "JDG", "RUT", "1SA", "2SA",
    "1KI", "2KI", "1CH", "2CH", "EZR", "NEH", "EST", "JOB", "PSA", "PRO",
    "ECC", "SNG", "ISA", "JER", "LAM", "EZK", "DAN", "HOS", "JOL", "AMO",
    "OBA", "JON", "MIC", "NAM", "HAB", "ZEP", "HAG", "ZEC", "MAL", "MAT",
    "MRK", "LUK", "JHN", "ACT", "ROM", "1CO", "2CO", "GAL", "EPH", "PHP",
    "COL", "1TH", "2TH", "1TI", "2TI", "TIT", "PHM", "HEB", "JAS", "1PE",
    "2PE", "1JN", "2JN", "3JN", "JUD", "REV",
]
_BOOK_NUM = {code: str(i) for i, code in enumerate(_BOOK_ORDER, 1)}

_FOOTNOTE_RE = re.compile(r"\\f\b.*?\\f\*", re.DOTALL)
_XREF_RE = re.compile(r"\\x\b.*?\\x\*", re.DOTALL)
_WORD_RE = re.compile(r"\\\+?w\s+([^|\\]+?)(?:\|[^\\]*)?\\\+?w\*", re.DOTALL)
_MILESTONE_RE = re.compile(r"\\\+?(?:zaln|k)-[se]\b[^\\]*(?:\\\+?(?:zaln|k)-e\*)?", re.DOTALL)
_FORMATTED_PAREN_RE = re.compile(r"\\(?:it|bd|bdit)\s*\([^)]*\)\s*\\(?:it|bd|bdit)\*")
_MARKER_RE = re.compile(r"\\[a-z0-9+\-]+\*?(?:\s+)?", re.IGNORECASE)


@dataclass(frozen=True)
class Translation:
    lang: str
    ebible_id: str
    out_file: str
    identify: str
    name: str
    shortname: str
    year: str
    native_language: str
    iso_639_1: str
    iso_639_3: str
    direction: str
    description: str
    publisher: str
    copyright: str
    bible_label: str
    book_label: str
    chapter_label: str
    verse_label: str
    ot_label: str
    nt_label: str


TRANSLATIONS: dict[str, Translation] = {
    "ar": Translation(
        lang="ar",
        ebible_id="arb-vd",
        out_file="arabic_vandyke.json",
        identify="arb-vd",
        name="الكتاب المقدس باللغة العربية، فان دايك",
        shortname="AVD",
        year="1865",
        native_language="العربية",
        iso_639_1="ar",
        iso_639_3="arb",
        direction="rtl",
        description="Arabic Van Dyck Bible",
        publisher="American Bible Society",
        copyright="Public Domain.",
        bible_label="الكتاب المقدس",
        book_label="سفر",
        chapter_label="أصحاح",
        verse_label="آية",
        ot_label="العهد القديم",
        nt_label="العهد الجديد",
    ),
    "zh-CN": Translation(
        lang="zh-CN",
        ebible_id="cmn-cu89s",
        out_file="chinese_union_simplified.json",
        identify="cmn-cu89s",
        name="新标点和合本",
        shortname="CUVs",
        year="1989",
        native_language="简体中文",
        iso_639_1="zh",
        iso_639_3="cmn",
        direction="ltr",
        description="Chinese Union Version (simplified)",
        publisher="eBible.org",
        copyright="Public Domain.",
        bible_label="圣经",
        book_label="书卷",
        chapter_label="章",
        verse_label="节",
        ot_label="旧约",
        nt_label="新约",
    ),
    "es": Translation(
        lang="es",
        ebible_id="spaRV1909",
        out_file="spanish_rv1909.json",
        identify="spaRV1909",
        name="Santa Biblia — Reina Valera 1909",
        shortname="RV1909",
        year="1909",
        native_language="Español",
        iso_639_1="es",
        iso_639_3="spa",
        direction="ltr",
        description="Reina Valera 1909",
        publisher="eBible.org",
        copyright="Public Domain.",
        bible_label="Biblia",
        book_label="Libro",
        chapter_label="Capítulo",
        verse_label="Versículo",
        ot_label="Antiguo Testamento",
        nt_label="Nuevo Testamento",
    ),
    "th": Translation(
        lang="th",
        ebible_id="thaKJV",
        out_file="thai_kjv.json",
        identify="thaKJV",
        name="พระคัมภีร์ภาษาไทยฉบับ KJV",
        shortname="THAKJV",
        year="2003",
        native_language="ไทย",
        iso_639_1="th",
        iso_639_3="tha",
        direction="ltr",
        description="Thai KJV Bible",
        publisher="Philip Pope",
        copyright=(
            "Copyright © 2003 Philip Pope. Licensed under Creative Commons "
            "Attribution-Noncommercial-No Derivatives 4.0."
        ),
        bible_label="พระคัมภีร์",
        book_label="เล่ม",
        chapter_label="บท",
        verse_label="ข้อ",
        ot_label="พันธสัญญาเดิม",
        nt_label="พันธสัญญาใหม่",
    ),
}


def _fetch(url: str) -> bytes:
    req = urllib.request.Request(url, headers=_HEADERS)
    with urllib.request.urlopen(req, timeout=120) as resp:
        return resp.read()


def _metadata(text: str) -> tuple[str, str]:
    toc1 = ""
    toc2 = ""
    short = ""
    for line in text.splitlines():
        if line.startswith("\\toc1 "):
            toc1 = line[6:].strip()
        elif line.startswith("\\toc2 "):
            toc2 = line[6:].strip()
        elif line.startswith("\\toc3 "):
            short = line[6:].strip()
        if (toc2 or toc1) and short:
            break
    return toc2 or toc1, short


def _verse_numbers(token: str) -> list[str]:
    match = re.match(r"^(\d+)(?:[-–](\d+))?$", token)
    if not match:
        return []
    start = int(match.group(1))
    end = int(match.group(2) or start)
    return [str(n) for n in range(start, end + 1)]


def _clean_verse(raw: str) -> str:
    raw = _FOOTNOTE_RE.sub("", raw)
    raw = _XREF_RE.sub("", raw)
    raw = _WORD_RE.sub(lambda m: m.group(1), raw)
    raw = _MILESTONE_RE.sub("", raw)
    raw = _FORMATTED_PAREN_RE.sub("", raw)
    raw = raw.replace("\\it*", "").replace("\\it ", "")
    raw = raw.replace("\\bd*", "").replace("\\bd ", "")
    raw = raw.replace("\\bdit*", "").replace("\\bdit ", "")
    raw = raw.replace("~", " ")
    raw = _MARKER_RE.sub("", raw)
    return re.sub(r"\s+", " ", raw).strip()


def _parse_usfm(text: str) -> tuple[str, dict]:
    code_match = re.search(r"^\\id\s+([A-Z0-9]{3})", text, re.MULTILINE)
    if not code_match:
        raise ValueError("USFM file is missing \\id")
    code = code_match.group(1)
    if code not in _BOOK_NUM:
        raise ValueError(f"Unexpected USFM book code {code!r}")

    name, short = _metadata(text)
    book = {
        "info": {"name": name or code, "shortname": short, "abbr": [], "desc": ""},
        "chapter": {},
    }

    chapter = ""
    verses: list[str] = []
    buf: list[str] = []

    def flush() -> None:
        nonlocal buf
        if not (chapter and verses):
            buf = []
            return
        cleaned = _clean_verse(" ".join(buf))
        if cleaned:
            chapter_obj = book["chapter"].setdefault(chapter, {"verse": {}})
            for verse_num in verses:
                chapter_obj["verse"][verse_num] = {"text": cleaned}
        buf = []

    for line in text.splitlines():
        line = line.strip()
        if not line:
            continue
        c = re.match(r"^\\c\s+(\d+)", line)
        if c:
            flush()
            chapter = c.group(1)
            verses = []
            book["chapter"].setdefault(chapter, {"verse": {}})
            continue
        v = re.match(r"^\\v\s+([0-9]+(?:[-–][0-9]+)?)\s*(.*)", line)
        if v:
            flush()
            verses = _verse_numbers(v.group(1))
            buf = [v.group(2)]
            continue
        if chapter and verses:
            if not re.match(r"^\\(?:p|m|q\d*|s\d*|ms\d*|r|d|sp|li\d*)\b", line):
                buf.append(line)
            else:
                content = re.sub(r"^\\[a-z0-9]+\s*", "", line, flags=re.IGNORECASE)
                if content:
                    buf.append(content)
    flush()

    return code, book


def build(lang: str) -> dict:
    cfg = TRANSLATIONS[lang]
    archive = zipfile.ZipFile(io.BytesIO(_fetch(f"https://ebible.org/Scriptures/{cfg.ebible_id}_usfm.zip")))
    books: dict[str, dict] = {}
    for name in archive.namelist():
        if not name.endswith(".usfm"):
            continue
        text = archive.read(name).decode("utf-8-sig")
        code_match = re.search(r"^\\id\s+([A-Z0-9]{3})", text, re.MULTILINE)
        if not code_match or code_match.group(1) not in _BOOK_NUM:
            continue
        code, book = _parse_usfm(text)
        books[_BOOK_NUM[code]] = book

    if sorted(map(int, books)) != list(range(1, 67)):
        raise RuntimeError(f"Expected canonical 66-book {lang} Bible, got {sorted(books, key=int)}")

    return {
        "info": {
            "identify": cfg.identify,
            "name": cfg.name,
            "shortname": cfg.shortname,
            "year": cfg.year,
            "language": {
                "text": cfg.native_language,
                "textdirection": cfg.direction,
                "iso": {"639-1": cfg.iso_639_1, "639-3": cfg.iso_639_3},
                "name": cfg.iso_639_3,
            },
            "version": 1,
            "description": cfg.description,
            "publisher": cfg.publisher,
            "contributors": "",
            "copyright": cfg.copyright,
        },
        "note": {},
        "digit": ["0", "1", "2", "3", "4", "5", "6", "7", "8", "9"],
        "language": {
            "bible": cfg.bible_label,
            "book": cfg.book_label,
            "chapter": cfg.chapter_label,
            "verse": cfg.verse_label,
        },
        "testament": {
            "1": {"info": {"name": cfg.ot_label, "shortname": "OT", "desc": ""}, "other": {}},
            "2": {"info": {"name": cfg.nt_label, "shortname": "NT", "desc": ""}, "other": {}},
        },
        "story": {},
        "book": dict(sorted(books.items(), key=lambda item: int(item[0]))),
    }


def write(lang: str, force: bool = False) -> str:
    cfg = TRANSLATIONS[lang]
    os.makedirs(DATA_DIR, exist_ok=True)
    out = os.path.join(DATA_DIR, cfg.out_file)
    if os.path.exists(out) and not force:
        return out
    payload = build(lang)
    with open(out, "w", encoding="utf-8") as fh:
        json.dump(payload, fh, ensure_ascii=False, indent=1)
        fh.write("\n")
    return out


def _verse_count(payload: dict) -> int:
    return sum(
        len(ch["verse"])
        for book in payload["book"].values()
        for ch in book["chapter"].values()
    )


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("langs", nargs="*", choices=sorted(TRANSLATIONS), default=sorted(TRANSLATIONS))
    ap.add_argument("--force", action="store_true", help="re-download and overwrite existing outputs")
    args = ap.parse_args()

    for lang in args.langs:
        path = write(lang, force=args.force)
        with open(path, encoding="utf-8") as fh:
            payload = json.load(fh)
        print(f"{os.path.basename(path)}: {len(payload['book'])} books, {_verse_count(payload)} verses")


if __name__ == "__main__":
    main()
