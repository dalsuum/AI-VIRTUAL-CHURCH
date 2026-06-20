"""Build the Hebrew Tanakh (Westminster Leningrad Codex) into data/wlc.json.

The online Bible reader serves each translation from a single JSON file in the
dalsuum/bible schema (book -> chapter -> verse -> {"text": ...}); see
workers/bible_api.py. English/Myanmar/Tedim are vendored straight from
dalsuum/bible. Hebrew has no file there yet, so this tool builds one from a
public-domain WLC source and writes it in that same schema:

    pip install requests
    python workers/tools/build_hebrew_bible.py            # -> data/wlc.json

Source: getbible.net API v2 module `codex` — "OT Westminster Leningrad Codex"
(public domain; the WLC text itself is public domain). The Tanakh is the Old
Testament only, so the module has exactly 39 books (Genesis=1 … Malachi=39),
already numbered on the canonical 1-66 scheme the reader expects. Book names are
the WLC's own pointed Hebrew (בְּרֵאשִׁית); verse text keeps the vowel points and
cantillation marks as delivered. The reader renders it right-to-left.

The resulting wlc.json is a drop-in for the reader (BIBLE_DATA_FILE_HE). It is
also the file to commit to dalsuum/bible so it seeds like the others; once it
lives there, seed_language_data.py downloads it instead of rebuilding.

Safe to re-run: pass --force to overwrite an existing data/wlc.json.
"""

from __future__ import annotations

import argparse
import json
import os

import requests

DATA_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "data")
OUT_FILE = os.path.join(DATA_DIR, "wlc.json")

# getbible.net v2, module "codex" = OT Westminster Leningrad Codex (39 books).
GETBIBLE_BASE = os.getenv("GETBIBLE_BASE", "https://api.getbible.net/v2/codex")
OT_BOOKS = 39  # Genesis (1) … Malachi (39); the Tanakh has no New Testament.
_HEADERS = {"User-Agent": "ai-church-bible-seeder/1.0"}


def _fetch_book(num: int) -> dict:
    """One book from getbible: {nr, name, chapters:[{chapter, verses:[{verse,text}]}]}."""
    resp = requests.get(f"{GETBIBLE_BASE}/{num}.json", headers=_HEADERS, timeout=60)
    resp.raise_for_status()
    return resp.json()


def build() -> dict:
    """Fetch all 39 OT books and shape them into the dalsuum/bible schema."""
    books: dict[str, dict] = {}
    for num in range(1, OT_BOOKS + 1):
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
    ap.add_argument("--force", action="store_true", help="overwrite an existing data/wlc.json")
    args = ap.parse_args()

    os.makedirs(DATA_DIR, exist_ok=True)
    if os.path.exists(OUT_FILE) and not args.force:
        print(f"{OUT_FILE} already present — pass --force to rebuild.")
        return

    print("Building Hebrew Tanakh (WLC) from getbible.net codex…")
    data = build()

    # Fail loudly before writing if the canon looks wrong.
    assert len(data["book"]) == OT_BOOKS, f"expected {OT_BOOKS} books, got {len(data['book'])}"
    assert data["book"]["1"]["chapter"].get("1"), "Genesis 1 missing"
    assert data["book"]["1"]["chapter"]["1"]["verse"].get("1", {}).get("text"), "Genesis 1:1 empty"

    json.dump(data, open(OUT_FILE, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    size = os.path.getsize(OUT_FILE) / 1e6
    print(f"Done. data/wlc.json: {len(data['book'])} books, {size:.1f} MB ✓")


if __name__ == "__main__":
    main()
