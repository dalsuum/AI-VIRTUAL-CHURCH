"""Build selected getBible v2 translations into the local Bible JSON schema.

Currently used for Korean because the public-domain eBible `kor` USFM bundle is
missing 1 Peter chapter 5, while getBible's public-domain `korean` module has the
full canonical chapter set.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import time
import urllib.request
from dataclasses import dataclass
from concurrent.futures import ThreadPoolExecutor, as_completed

DATA_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "data")
_HEADERS = {"User-Agent": "ai-church-bible-seeder/1.0"}
_BASE = "https://api.getbible.net/v2"


@dataclass(frozen=True)
class Translation:
    lang: str
    getbible_id: str
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
    "ko": Translation(
        lang="ko",
        getbible_id="korean",
        out_file="korean_krv.json",
        identify="korean",
        name="개역성경",
        shortname="KRV",
        year="1952/1961",
        native_language="한국어",
        iso_639_1="ko",
        iso_639_3="kor",
        direction="ltr",
        description="Korean Revised Version",
        publisher="Wikisource / CrossWire / getBible",
        copyright="Public Domain.",
        bible_label="성경",
        book_label="권",
        chapter_label="장",
        verse_label="절",
        ot_label="구약",
        nt_label="신약",
    ),
}


def _fetch_json(url: str) -> dict:
    last_exc: Exception | None = None
    for attempt in range(4):
        try:
            req = urllib.request.Request(url, headers=_HEADERS)
            with urllib.request.urlopen(req, timeout=25) as resp:
                return json.load(resp)
        except Exception as exc:  # noqa: BLE001 - retry transient network stalls
            last_exc = exc
            time.sleep(0.5 * (attempt + 1))
    raise RuntimeError(f"Could not fetch {url}: {last_exc}") from last_exc


def _clean_text(text: str) -> str:
    return re.sub(r"\s+", " ", (text or "").replace("\u00a0", " ")).strip()


def build(lang: str) -> dict:
    cfg = TRANSLATIONS[lang]
    books_meta = _fetch_json(f"{_BASE}/{cfg.getbible_id}/books.json")
    books: dict[str, dict] = {}

    for book_num in range(1, 67):
        meta = books_meta[str(book_num)]
        chapters_meta = _fetch_json(f"{_BASE}/{cfg.getbible_id}/{book_num}/chapters.json")
        book = {
            "info": {
                "name": meta.get("name") or f"Book {book_num}",
                "shortname": "",
                "abbr": [],
                "desc": "",
            },
            "chapter": {},
        }
        chapter_nums = sorted((int(k) for k in chapters_meta.keys()))

        def fetch_chapter(chapter_num: int) -> tuple[int, dict]:
            chapter_payload = _fetch_json(f"{_BASE}/{cfg.getbible_id}/{book_num}/{chapter_num}.json")
            verses = {}
            for verse in chapter_payload.get("verses", []):
                verse_num = str(int(verse["verse"]))
                text = _clean_text(verse.get("text", ""))
                if text:
                    verses[verse_num] = {"text": text}
            return chapter_num, {"verse": verses}

        with ThreadPoolExecutor(max_workers=12) as pool:
            futures = [pool.submit(fetch_chapter, chapter_num) for chapter_num in chapter_nums]
            for fut in as_completed(futures):
                chapter_num, chapter = fut.result()
                book["chapter"][str(chapter_num)] = chapter
        book["chapter"] = dict(sorted(book["chapter"].items(), key=lambda item: int(item[0])))
        books[str(book_num)] = book

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
        "book": books,
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
