"""Build Hindi IRV 2019 into the local dalsuum/bible JSON schema.

Source: eBible.org hin2017 USFM bundle. The translation is licensed under
Creative Commons Attribution-ShareAlike 4.0 by Bridge Connectivity Solutions.

    python workers/tools/build_hindi_irv_bible.py

The output matches workers/bible_api.py's expected schema:
book -> chapter -> verse -> {"text": "..."} with canonical 1-66 book numbers.
USFM study notes/cross-references are stripped so reader/search/AI consumers get
verse text only, not apparatus text.
"""

from __future__ import annotations

import io
import json
import os
import re
import urllib.request
import zipfile

DATA_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "data")
OUT_FILE = os.path.join(DATA_DIR, "hindi_irv2019.json")
SOURCE_URL = os.getenv(
    "HINDI_IRV_USFM_URL",
    "https://ebible.org/Scriptures/hin2017_usfm.zip",
)

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
_FORMATTED_PAREN_RE = re.compile(r"\\(?:it|bd|bdit)\s*\([^)]*\)\s*\\(?:it|bd|bdit)\*")
_MARKER_RE = re.compile(r"\\[a-z0-9]+\*?(?:\s+)?", re.IGNORECASE)


def _fetch(url: str) -> bytes:
    req = urllib.request.Request(url, headers=_HEADERS)
    with urllib.request.urlopen(req, timeout=120) as resp:
        return resp.read()


def _metadata(text: str) -> tuple[str, str]:
    name = ""
    short = ""
    for line in text.splitlines():
        if line.startswith("\\toc1 "):
            name = line[6:].strip()
        elif line.startswith("\\toc3 "):
            short = line[6:].strip()
        if name and short:
            break
    return name, short


def _clean_verse(raw: str) -> str:
    raw = _FOOTNOTE_RE.sub("", raw)
    raw = _XREF_RE.sub("", raw)
    raw = _FORMATTED_PAREN_RE.sub("", raw)
    raw = raw.replace("\\it*", "").replace("\\it ", "")
    raw = raw.replace("\\bd*", "").replace("\\bd ", "")
    raw = raw.replace("\\bdit*", "").replace("\\bdit ", "")
    raw = _MARKER_RE.sub("", raw)
    return re.sub(r"\s+", " ", raw).strip()


def _parse_usfm(text: str) -> dict:
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
    verse = ""
    buf: list[str] = []

    def flush() -> None:
        nonlocal buf
        if not (chapter and verse):
            buf = []
            return
        cleaned = _clean_verse(" ".join(buf))
        if cleaned:
            book["chapter"].setdefault(chapter, {"verse": {}})["verse"][verse] = {"text": cleaned}
        buf = []

    for line in text.splitlines():
        line = line.strip()
        if not line:
            continue
        c = re.match(r"^\\c\s+(\d+)", line)
        if c:
            flush()
            chapter = c.group(1)
            verse = ""
            book["chapter"].setdefault(chapter, {"verse": {}})
            continue
        v = re.match(r"^\\v\s+(\d+)\s*(.*)", line)
        if v:
            flush()
            verse = v.group(1)
            buf = [v.group(2)]
            continue
        if chapter and verse:
            # Continuation lines inside poetry/paragraphs belong to the current verse.
            if not re.match(r"^\\(?:p|m|q\d*|s\d*|ms\d*|r|d|sp)\b", line):
                buf.append(line)
            else:
                content = re.sub(r"^\\[a-z0-9]+\s*", "", line, flags=re.IGNORECASE)
                if content:
                    buf.append(content)
    flush()

    return book


def build() -> dict:
    archive = zipfile.ZipFile(io.BytesIO(_fetch(SOURCE_URL)))
    books: dict[str, dict] = {}
    for name in archive.namelist():
        if not name.endswith(".usfm"):
            continue
        text = archive.read(name).decode("utf-8-sig")
        code = re.search(r"^\\id\s+([A-Z0-9]{3})", text, re.MULTILINE).group(1)
        if code not in _BOOK_NUM:
            continue
        books[_BOOK_NUM[code]] = _parse_usfm(text)

    if sorted(map(int, books)) != list(range(1, 67)):
        raise RuntimeError(f"Expected canonical 66-book Hindi Bible, got {sorted(books, key=int)}")

    return {
        "info": {
            "identify": "hin2017",
            "name": "इंडियन रिवाइज्ड वर्जन (IRV) - हिन्दी",
            "shortname": "HINIRV",
            "year": "2019",
            "language": {
                "text": "हिन्दी",
                "textdirection": "ltr",
                "iso": {"639-1": "hi", "639-3": "hin"},
                "name": "hin",
            },
            "version": 1,
            "description": "Hindi Indian Revised Version Bible",
            "publisher": "Bridge Connectivity Solutions Pvt. Ltd.",
            "contributors": "Bridge Connectivity Solutions",
            "copyright": (
                "Hindi Indian Revised Version, 2019 by Bridge Connectivity Solutions "
                "is licensed under Creative Commons Attribution-ShareAlike 4.0 International."
            ),
        },
        "note": {},
        "digit": ["0", "1", "2", "3", "4", "5", "6", "7", "8", "9"],
        "language": {"bible": "बाइबिल", "book": "पुस्तक", "chapter": "अध्याय", "verse": "वचन"},
        "testament": {
            "1": {"info": {"name": "पुराना नियम", "shortname": "OT", "desc": ""}, "other": {}},
            "2": {"info": {"name": "नया नियम", "shortname": "NT", "desc": ""}, "other": {}},
        },
        "story": {},
        "book": dict(sorted(books.items(), key=lambda item: int(item[0]))),
    }


def main() -> None:
    os.makedirs(DATA_DIR, exist_ok=True)
    payload = build()
    with open(OUT_FILE, "w", encoding="utf-8") as fh:
        json.dump(payload, fh, ensure_ascii=False, indent=1)
        fh.write("\n")
    verse_count = sum(
        len(ch["verse"])
        for book in payload["book"].values()
        for ch in book["chapter"].values()
    )
    print(f"hindi_irv2019.json: {len(payload['book'])} books, {verse_count} verses")


if __name__ == "__main__":
    main()
