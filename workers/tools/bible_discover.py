"""Discover Bible translations in a dalsuum/bible checkout, with license triage.

Being on GitHub does NOT mean a text may be redistributed. This helper reads a
local checkout's `book.json` (the manifest the repo keeps for every translation:
identify, name, year, language, textdirection, copyright/publisher) and reports,
per translation, the metadata plus a conservative `redistributable` verdict, so
vendoring a new Bible into this app is a deliberate, documented step rather than
a guess.

    python workers/tools/bible_discover.py /path/to/dalsuum-bible
    python workers/tools/bible_discover.py /path/to/checkout --only ta,de,ja

Verdict heuristic (intentionally conservative — when unsure, "review"):
  - "public-domain": no copyright noted AND year < PUBLIC_DOMAIN_BEFORE.
  - "free-license":  copyright text names a permissive/Creative-Commons grant
                     (CC BY / CC BY-SA), excluding NonCommercial/NoDerivs.
  - "restricted":    copyright text is present and clearly proprietary, or names
                     an NC/ND Creative Commons variant.
  - "review":        anything ambiguous — a human must verify before bundling.

It NEVER copies text. To actually vendor an approved translation, copy its
`json/<identify>.json` into workers/data/ and register it in bible_api._LANG_FILES
(or set BIBLE_DATA_FILE_<LANG>); see that file + README.
"""

from __future__ import annotations

import argparse
import json
import os
import re

# Editions printed before this year, with no copyright noted, are treated as
# public domain (worldwide). Conservative: many countries are life+70, but the
# major historic Bible translations (Luther 1912, Ostervald 1877, Van Dyck 1865,
# KJV) are comfortably clear well before this cut-off.
PUBLIC_DOMAIN_BEFORE = 1925

_CC_FREE = re.compile(r"\bCC[\s-]?BY(?:[\s-]?SA)?\b|creative commons", re.IGNORECASE)
_CC_RESTRICTED = re.compile(r"\bNC\b|non[\s-]?commercial|\bND\b|no[\s-]?deriv", re.IGNORECASE)
_HAS_COPYRIGHT = re.compile(r"©|\(c\)|copyright|all rights reserved", re.IGNORECASE)


def verdict(year: int, copyright_text: str) -> str:
    text = (copyright_text or "").strip()
    if _CC_FREE.search(text) and not _CC_RESTRICTED.search(text):
        return "free-license"
    if _CC_RESTRICTED.search(text):
        return "restricted"
    if not text or not _HAS_COPYRIGHT.search(text):
        return "public-domain" if (year and year < PUBLIC_DOMAIN_BEFORE) else "review"
    return "restricted"


def discover(checkout: str) -> list[dict]:
    manifest = os.path.join(checkout, "book.json")
    if not os.path.isfile(manifest):
        raise SystemExit(f"No book.json under {checkout!r} — is this a dalsuum/bible checkout?")
    with open(manifest, encoding="utf-8") as fh:
        data = json.load(fh)

    rows: list[dict] = []
    for b in data.get("book", []):
        lang = b.get("language", {}) or {}
        try:
            year = int(str(b.get("year", "")).strip()[:4])
        except (ValueError, TypeError):
            year = 0
        cr = b.get("copyright", "") or ""
        rows.append({
            "identify": b.get("identify", ""),
            "lang": lang.get("name", ""),
            "name": b.get("name", ""),
            "year": year,
            "rtl": (lang.get("textdirection", "ltr") == "rtl"),
            "copyright": cr.strip(),
            "verdict": verdict(year, cr),
        })
    return rows


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("checkout", help="path to a local dalsuum/bible checkout")
    ap.add_argument("--only", help="comma-separated language codes to filter (e.g. ta,de,ja)")
    args = ap.parse_args()

    rows = discover(args.checkout)
    if args.only:
        wanted = {c.strip() for c in args.only.split(",")}
        rows = [r for r in rows if r["lang"] in wanted]

    rows.sort(key=lambda r: (r["verdict"], r["lang"]))
    print(f"{'verdict':14} {'lang':6} {'id':14} {'year':5} rtl  name")
    print("-" * 78)
    for r in rows:
        print(f"{r['verdict']:14} {r['lang']:6} {str(r['identify']):14} "
              f"{r['year'] or '?':5} {'yes' if r['rtl'] else ' no':3}  {r['name']}")
    free = sum(r["verdict"] in ("public-domain", "free-license") for r in rows)
    print(f"\n{free}/{len(rows)} look bundlable; verify 'review'/'restricted' before vendoring.")


if __name__ == "__main__":
    main()
