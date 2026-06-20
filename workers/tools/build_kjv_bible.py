"""Build the English Authorized (King James) Version into data/kjv.json.

The online Bible reader serves each translation from a single JSON file in the
dalsuum/bible schema (book -> chapter -> verse -> {"text": ...}); see
workers/bible_api.py. English already ships the Berean Standard Bible (bsb.json);
this tool adds the King James Version as a second English edition, written in
that same schema:

    pip install requests
    python workers/tools/build_kjv_bible.py            # -> data/kjv.json

Source: getbible.net API v2 module `kjv` — the Authorized (King James) Version,
public domain (the 1769 Blayney text is public domain worldwide outside the UK,
where the Crown holds letters patent; this app does not distribute in the UK).
The KJV is the full Protestant canon: exactly 66 books (Genesis=1 …
Revelation=66), already numbered on the canonical 1-66 scheme the reader expects,
so its book numbers line up with the vendored English index (data/books_en.json).

The resulting kjv.json is a drop-in for the reader (BIBLE_DATA_FILE_KJV). It is
also the file to commit to dalsuum/bible so it seeds like the others; once it
lives there, seed_language_data.py downloads it instead of rebuilding.

Safe to re-run: pass --force to overwrite an existing data/kjv.json.
"""

from __future__ import annotations

import argparse
import json
import os

import requests

DATA_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "data")
OUT_FILE = os.path.join(DATA_DIR, "kjv.json")

# getbible.net v2, module "kjv" = Authorized (King James) Version (66 books).
GETBIBLE_BASE = os.getenv("GETBIBLE_KJV_BASE", "https://api.getbible.net/v2/kjv")
CANON_BOOKS = 66  # Genesis (1) … Revelation (66); full Protestant canon.
_HEADERS = {"User-Agent": "ai-church-bible-seeder/1.0"}


def _fetch_book(num: int) -> dict:
    """One book from getbible: {nr, name, chapters:[{chapter, verses:[{verse,text}]}]}."""
    resp = requests.get(f"{GETBIBLE_BASE}/{num}.json", headers=_HEADERS, timeout=60)
    resp.raise_for_status()
    return resp.json()


def build() -> dict:
    """Fetch all 66 books and shape them into the dalsuum/bible schema."""
    books: dict[str, dict] = {}
    for num in range(1, CANON_BOOKS + 1):
        src = _fetch_book(num)
        chapters: dict[str, dict] = {}
        for ch in src.get("chapters", []):
            verses = {
                str(v["verse"]): {"text": (v.get("text") or "").strip()}
                for v in ch.get("verses", [])
            }
            chapters[str(ch["chapter"])] = {"verse": verses}
        books[str(num)] = {
            "info": {"name": src.get("name", "").strip(), "shortname": "", "abbr": [], "desc": ""},
            "chapter": chapters,
        }
        print(f"  book {num:>2}: {src.get('name','')}  ({len(chapters)} chapters)")
    return {"book": books}


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--force", action="store_true", help="overwrite an existing data/kjv.json")
    args = ap.parse_args()

    os.makedirs(DATA_DIR, exist_ok=True)
    if os.path.exists(OUT_FILE) and not args.force:
        print(f"{OUT_FILE} already present — pass --force to rebuild.")
        return

    print("Building English Authorized (King James) Version from getbible.net kjv…")
    data = build()

    # Fail loudly before writing if the canon looks wrong.
    assert len(data["book"]) == CANON_BOOKS, f"expected {CANON_BOOKS} books, got {len(data['book'])}"
    assert data["book"]["1"]["chapter"].get("1"), "Genesis 1 missing"
    assert data["book"]["1"]["chapter"]["1"]["verse"].get("1", {}).get("text"), "Genesis 1:1 empty"
    assert data["book"]["43"]["chapter"]["3"]["verse"].get("16", {}).get("text"), "John 3:16 empty"

    json.dump(data, open(OUT_FILE, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    size = os.path.getsize(OUT_FILE) / 1e6
    print(f"Done. data/kjv.json: {len(data['book'])} books, {size:.1f} MB ✓")


if __name__ == "__main__":
    main()
