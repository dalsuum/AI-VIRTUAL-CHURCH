"""Seed the multilingual data files into workers/data/ (gitignored, like bsb.json).

This repo's convention keeps workers/data/ out of git — bsb.json is vendored at
deploy, not committed. The Myanmar/Tedim languages follow the same rule, so run
this once per machine (before first service, alongside seed_hymns.py):

    pip install requests
    python workers/tools/seed_language_data.py

It produces the Bible/text files used by workers/bible_api.py:

    data/kjv.json          Authorized (King James) Version — from
                           dalsuum/bible if present, else built from getbible.net
                           by build_kjv_bible.py (full 66-book canon)
    data/judson1835.json   သမ္မာကျမ်း (Judson, 1835)  — github.com/dalsuum/bible
    data/tedim1932.json    Lai Siangtho (Tedim, 1932) — github.com/dalsuum/bible
    data/wlc.json          Hebrew Tanakh (Westminster Leningrad Codex) — from
                           dalsuum/bible if present, else built from getbible.net
                           by build_hebrew_bible.py (Old Testament only, RTL)
    data/japanese_colloquial1955.json
                           Colloquial Japanese (1955) — github.com/dalsuum/bible
    data/hindi_irv2019.json
                           Hindi Indian Revised Version (2019) — built from
                           eBible.org hin2017 USFM (CC BY-SA 4.0)
    data/arabic_vandyke.json
                           Arabic Van Dyck Bible — built from eBible.org arb-vd
                           USFM (public domain)
    data/chinese_union_simplified.json
                           Chinese Union Version (simplified) — built from
                           eBible.org cmn-cu89s USFM (public domain)
    data/spanish_rv1909.json
                           Reina Valera 1909 — built from eBible.org spaRV1909
                           USFM (public domain)
    data/thai_kjv.json
                           Thai KJV Bible — built from eBible.org thaKJV USFM
                           (CC BY-NC-ND 4.0)
    data/korean_krv.json   Korean Revised Version — built from getBible v2
                           Korean module (public domain)
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

BIBLES = {
    "judson1835.json": "/json/judson1835.json",
    "tedim1932.json": "/json/tedim1932.json",
    # Chin/Zo language Bibles from the Bible Society of Myanmar (dalsuum/bible),
    # same schema as the others. See workers/bible_api.py _LANG_FILES.
    "falam1973.json": "/json/falam1973.json",
    "hakha1920.json": "/json/hakha1920.json",
    "mizo1917.json": "/json/mizo1917.json",
    "paite1971.json": "/json/paite1971.json",
    "sizang1932.json": "/json/sizang1932.json",
    "mara2011.json": "/json/mara2011.json",
    "matu2009.json": "/json/matu2009.json",
}


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


def _seed_japanese(force: bool) -> None:
    """Colloquial Japanese 1955 -> data/japanese_colloquial1955.json.

    Public-domain edition, already available in dalsuum/bible's shared JSON
    schema as identify 81.
    """
    out = os.path.join(DATA_DIR, "japanese_colloquial1955.json")
    if os.path.exists(out) and not force:
        print("  japanese_colloquial1955.json: already present, skipping")
        return
    data = _fetch(BIBLE_RAW + "/json/81.json")
    payload = json.loads(data)
    assert len(payload.get("book", {})) == 66, "unexpected Japanese canon"
    open(out, "wb").write(data)
    print(f"  japanese_colloquial1955.json: {len(data) / 1e6:.1f} MB ✓")


def _seed_hindi(force: bool) -> None:
    """Hindi IRV 2019 -> data/hindi_irv2019.json.

    Built from eBible's hin2017 USFM so study notes/cross-references can be
    stripped before the reader/search/AI surfaces consume verse text.
    """
    out = os.path.join(DATA_DIR, "hindi_irv2019.json")
    if os.path.exists(out) and not force:
        print("  hindi_irv2019.json: already present, skipping")
        return
    import build_hindi_irv_bible  # noqa: PLC0415 — sibling tool, path set above

    payload = build_hindi_irv_bible.build()
    with open(out, "w", encoding="utf-8") as fh:
        json.dump(payload, fh, ensure_ascii=False, indent=1)
        fh.write("\n")
    print(f"  hindi_irv2019.json: {len(payload['book'])} books ✓ (built from eBible USFM)")


def _seed_ebible_world_bibles(force: bool) -> None:
    import build_ebible_bible  # noqa: PLC0415 — sibling tool, path set above

    for lang in ("ar", "zh-CN", "es", "th"):
        cfg = build_ebible_bible.TRANSLATIONS[lang]
        out = os.path.join(DATA_DIR, cfg.out_file)
        if os.path.exists(out) and not force:
            print(f"  {cfg.out_file}: already present, skipping")
            continue
        payload = build_ebible_bible.build(lang)
        with open(out, "w", encoding="utf-8") as fh:
            json.dump(payload, fh, ensure_ascii=False, indent=1)
            fh.write("\n")
        print(f"  {cfg.out_file}: {len(payload['book'])} books ✓ (built from eBible USFM)")


def _seed_getbible_world_bibles(force: bool) -> None:
    import build_getbible_bible  # noqa: PLC0415 — sibling tool, path set above

    for lang in ("ko",):
        cfg = build_getbible_bible.TRANSLATIONS[lang]
        out = os.path.join(DATA_DIR, cfg.out_file)
        if os.path.exists(out) and not force:
            print(f"  {cfg.out_file}: already present, skipping")
            continue
        payload = build_getbible_bible.build(lang)
        with open(out, "w", encoding="utf-8") as fh:
            json.dump(payload, fh, ensure_ascii=False, indent=1)
            fh.write("\n")
        print(f"  {cfg.out_file}: {len(payload['book'])} books ✓ (built from getBible v2)")


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
    print("Seeding Japanese Bible…")
    _seed_japanese(args.force)
    print("Seeding Hindi Bible…")
    _seed_hindi(args.force)
    print("Seeding additional eBible world-language Bibles…")
    _seed_ebible_world_bibles(args.force)
    print("Seeding getBible world-language Bibles…")
    _seed_getbible_world_bibles(args.force)
    print("Seeding canonical book index…")
    _seed_books_index(args.force)
    print("Seeding Myanmar hymn library (dalsuum/myanmar-hymns)…")
    _seed_myanmar_hymns(args.force)
    print("Done. Next: python workers/tools/seed_tedim_hymns.py  (Tedim hymnal)")


if __name__ == "__main__":
    main()
