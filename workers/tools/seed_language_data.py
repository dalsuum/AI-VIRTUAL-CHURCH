"""Seed the multilingual data files into workers/data/ (gitignored, like bsb.json).

This repo's convention keeps workers/data/ out of git — bsb.json is vendored at
deploy, not committed. The Myanmar/Tedim languages follow the same rule, so run
this once per machine (before first service, alongside seed_hymns.py):

    pip install requests
    python workers/tools/seed_language_data.py

It produces four files, all from dalsuum-owned public repos:

    data/kjv.json          Authorized (King James) Version — from
                           dalsuum/bible if present, else built from getbible.net
                           by build_kjv_bible.py (full 66-book canon)
    data/judson1835.json   သမ္မာကျမ်း (Judson, 1835)  — github.com/dalsuum/bible
    data/tedim1932.json    Lai Siangtho (Tedim, 1932) — github.com/dalsuum/bible
    data/wlc.json          Hebrew Tanakh (Westminster Leningrad Codex) — from
                           dalsuum/bible if present, else built from getbible.net
                           by build_hebrew_bible.py (Old Testament only, RTL)
    data/books_en.json     canonical 66-book English index, extracted from the
                           same repo's category.json (name/abbr -> book number)
    data/hymns_my.json     852 Burmese songs, built from
                           github.com/dalsuum/myanmar-hymns by
                           tools/import_myanmar_hymns.py (cleaning + mood tags)

The Tedim HYMNS are seeded separately by seed_tedim_hymns.py (run once per
deploy). Safe to re-run: existing files are kept
unless --force is given.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import tempfile

import requests

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

DATA_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "data")

BIBLE_RAW = os.getenv("DALSUUM_BIBLE_RAW", "https://raw.githubusercontent.com/dalsuum/bible/master")
HYMNS_RAW = os.getenv("DALSUUM_HYMNS_RAW", "https://raw.githubusercontent.com/dalsuum/myanmar-hymns/master")

BIBLES = {"judson1835.json": "/json/judson1835.json", "tedim1932.json": "/json/tedim1932.json"}


def _fetch(url: str) -> bytes:
    resp = requests.get(url, timeout=120)
    resp.raise_for_status()
    return resp.content


def _seed_bibles(force: bool) -> None:
    for name, path in BIBLES.items():
        out = os.path.join(DATA_DIR, name)
        if os.path.exists(out) and not force:
            print(f"  {name}: already present, skipping")
            continue
        data = _fetch(BIBLE_RAW + path)
        json.loads(data)  # fail loudly on a bad download, before writing
        open(out, "wb").write(data)
        print(f"  {name}: {len(data) / 1e6:.1f} MB ✓")


def _seed_hebrew(force: bool) -> None:
    """Hebrew Tanakh (WLC) -> data/wlc.json.

    Prefer a committed dalsuum/bible/json/wlc.json (same path as the other
    translations); if it isn't there yet, fall back to building it from the
    public-domain getbible.net WLC via build_hebrew_bible.
    """
    out = os.path.join(DATA_DIR, "wlc.json")
    if os.path.exists(out) and not force:
        print("  wlc.json: already present, skipping")
        return
    try:
        data = _fetch(BIBLE_RAW + "/json/wlc.json")
        json.loads(data)  # fail loudly on a bad download, before writing
        open(out, "wb").write(data)
        print(f"  wlc.json: {len(data) / 1e6:.1f} MB ✓ (from dalsuum/bible)")
        return
    except requests.HTTPError:
        print("  wlc.json: not in dalsuum/bible yet — building from getbible.net…")
    import build_hebrew_bible  # noqa: PLC0415 — sibling tool, path set above

    payload = build_hebrew_bible.build()
    json.dump(payload, open(out, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(f"  wlc.json: {len(payload['book'])} books ✓ (built from getbible.net)")


def _seed_kjv(force: bool) -> None:
    """English Authorized (King James) Version -> data/kjv.json.

    A second English edition alongside bsb.json. Prefer a committed
    dalsuum/bible/json/kjv.json (same path as the other translations); if it
    isn't there yet, fall back to building it from the public-domain getbible.net
    kjv module via build_kjv_bible.
    """
    out = os.path.join(DATA_DIR, "kjv.json")
    if os.path.exists(out) and not force:
        print("  kjv.json: already present, skipping")
        return
    try:
        data = _fetch(BIBLE_RAW + "/json/kjv.json")
        json.loads(data)  # fail loudly on a bad download, before writing
        open(out, "wb").write(data)
        print(f"  kjv.json: {len(data) / 1e6:.1f} MB ✓ (from dalsuum/bible)")
        return
    except requests.HTTPError:
        print("  kjv.json: not in dalsuum/bible yet — building from getbible.net…")
    import build_kjv_bible  # noqa: PLC0415 — sibling tool, path set above

    payload = build_kjv_bible.build()
    json.dump(payload, open(out, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(f"  kjv.json: {len(payload['book'])} books ✓ (built from getbible.net)")


def _seed_books_index(force: bool) -> None:
    out = os.path.join(DATA_DIR, "books_en.json")
    if os.path.exists(out) and not force:
        print("  books_en.json: already present, skipping")
        return
    cat = json.loads(_fetch(BIBLE_RAW + "/category.json"))
    books = {
        str(b["id"]): {
            "name": b["info"]["name"],
            "shortname": b["info"].get("shortname", ""),
            "abbr": b["info"].get("abbr", []),
        }
        for b in cat["book"]
    }
    assert len(books) == 66 and books["43"]["name"] == "John", "unexpected canonical index"
    json.dump(books, open(out, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(f"  books_en.json: {len(books)} books ✓")


def _seed_myanmar_hymns(force: bool) -> None:
    out = os.path.join(DATA_DIR, "hymns_my.json")
    if os.path.exists(out) and not force:
        print("  hymns_my.json: already present, skipping")
        return
    # import_myanmar_hymns expects a myanmar-hymns checkout layout; download just
    # the two songbook XMLs into a temp dir shaped like the repo and point it there.
    with tempfile.TemporaryDirectory() as tmp:
        songs = os.path.join(tmp, "assets", "songs")
        os.makedirs(songs)
        for fn in ("hymns.xml", "modern.xml"):
            open(os.path.join(songs, fn), "wb").write(_fetch(f"{HYMNS_RAW}/assets/songs/{fn}"))
        import import_myanmar_hymns  # noqa: PLC0415 — sibling tool, path set above

        sys.argv = ["import_myanmar_hymns.py", tmp]
        import_myanmar_hymns.main()


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--force", action="store_true", help="re-download even if files exist")
    args = ap.parse_args()

    os.makedirs(DATA_DIR, exist_ok=True)
    print("Seeding Bible translations (dalsuum/bible)…")
    _seed_bibles(args.force)
    print("Seeding English KJV…")
    _seed_kjv(args.force)
    print("Seeding Hebrew Tanakh (WLC)…")
    _seed_hebrew(args.force)
    print("Seeding canonical book index…")
    _seed_books_index(args.force)
    print("Seeding Myanmar hymn library (dalsuum/myanmar-hymns)…")
    _seed_myanmar_hymns(args.force)
    print("Done. Next: python workers/tools/seed_tedim_hymns.py  (Tedim hymnal)")


if __name__ == "__main__":
    main()
