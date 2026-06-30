"""Export the project's own Bible text to knowledge:ingest JSON format.

Reuses the SAME data the Bible reader serves — workers/bible_api.py — so there is
no second Bible source of truth: no scraping, no external API, no re-declared
file map. For each requested language it walks the canonical 1-66 books via
bible_api.list_books()/chapter() and emits ONE document per verse to
backend/storage/app/knowledge/bible_<lang>.json, shaped as [{id,text,metadata}].

Each verse is its own document because the BibleVerseChunker re-derives verse
boundaries by splitting on digit-prefixes — which corrupts modern translations
whose verse text contains numerals ("Methuselah lived 969 years", "the 12
tribes"). Verse-level documents + the prose chunker (--chunker=text) keep one
chunk per verse with an exact, hand-built reference instead. Every verse is well
under the chunker's 800-char target, so it stays a single chunk.

Then ingest (one collection, per language, on the worker box):

    php artisan knowledge:ingest bible storage/app/knowledge/bible_en.json --chunker=text

Usage:
    python workers/tools/export_bible.py [--langs en,my,td] [--out-dir DIR]

Default --langs is every translation bible_api advertises. Stamped `language`
is the translation's app code; retrieval filters on the conversation language,
so codes that are not conversation languages (e.g. 'kjv') will index but never
be retrieved — pick --langs accordingly.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

# bible_api lives one directory up; reuse it as the single source of truth.
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
import bible_api  # noqa: E402

_ROOT = Path(bible_api.__file__).resolve().parent.parent
_DEFAULT_OUT = _ROOT / "backend" / "storage" / "app" / "knowledge"


def _canonical_id(english_name: str) -> str:
    """Stable slug from the canonical English book name: 'Song of Solomon' -> 'song-of-solomon'."""
    return re.sub(r"-+", "-", re.sub(r"[^a-z0-9]+", "-", english_name.lower())).strip("-")


def _translation_label(lang: str) -> str:
    info = bible_api._bible(lang).get("info", {}) or {}
    return info.get("shortname") or info.get("name") or lang


def export_language(lang: str, out_dir: Path) -> int:
    label = _translation_label(lang)
    docs: list[dict] = []

    for book in bible_api.list_books(lang):
        if not book.get("available", True) or not book.get("chapters"):
            continue  # partial-canon placeholder (e.g. NT under the Hebrew Tanakh)
        num = int(book["num"])
        english_name = (book.get("aliases") or [book.get("name", "")])[0] or f"Book {num}"
        native_name = book.get("name", english_name)
        canonical_id = _canonical_id(english_name)

        for ch in range(1, int(book["chapters"]) + 1):
            for verse in bible_api.chapter(lang, num, ch)["verses"]:
                text = (verse.get("text") or "").strip()
                if not text:
                    continue
                v = int(verse["num"])
                docs.append({
                    "id": f"{canonical_id}.{ch}.{v}.{lang}",
                    "text": text,
                    "metadata": {
                        "source": "bible",
                        "language": lang,
                        "reference": f"{english_name} {ch}:{v}",
                        "permissions": ["public"],
                        "attributes": {
                            "book": english_name,
                            "book_number": num,
                            "chapter": ch,
                            "verse": v,
                            "canonical_id": canonical_id,
                            "translation": label,
                            "native_book_name": native_name,
                        },
                    },
                })

    out_path = out_dir / f"bible_{lang}.json"
    out_path.write_text(json.dumps(docs, ensure_ascii=False), encoding="utf-8")
    print(f"  {lang:<6} {label:<28} {len(docs):>6} verses -> {out_path.name}")
    return len(docs)


def main() -> int:
    parser = argparse.ArgumentParser(description="Export bundled Bible translations to knowledge:ingest JSON.")
    parser.add_argument("--langs", default="", help="comma-separated app codes (default: all advertised translations)")
    parser.add_argument("--out-dir", default=str(_DEFAULT_OUT), help="output directory for bible_<lang>.json files")
    args = parser.parse_args()

    available = bible_api.languages()
    langs = [c.strip() for c in args.langs.split(",") if c.strip()] or available
    unknown = [c for c in langs if c not in available]
    if unknown:
        print(f"ERROR: unknown/unavailable translation code(s): {', '.join(unknown)}", file=sys.stderr)
        print(f"       available: {', '.join(available)}", file=sys.stderr)
        return 1

    out_dir = Path(args.out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)

    total = 0
    for lang in langs:
        try:
            total += export_language(lang, out_dir)
        except Exception as exc:  # one bad translation must not abort the rest
            print(f"  skip {lang}: {exc}", file=sys.stderr)

    print(f"Exported {total} verse documents for {len(langs)} translation(s) into {out_dir}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
