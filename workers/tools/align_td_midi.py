"""OPTIONAL one-shot: align tedimhymn.com MIDI renders to hymns_td.json slugs.

The legacy MIDI archive (tedimhymn.com) and the modern lyrics repository
(hymns_td.json) romanize Tedim titles differently, so an exact normalized-title
join only catches a minority of tracks. This script bridges the orthographic
divergence OFFLINE — heavy fuzzy matching runs here, never at request time —
and emits a static map:

    data/td_midi_slug_map.json   { "<MIDI-NORM-TITLE>": "<hymn-slug>", ... }

`instrumental_hymn_strategy` loads that map and stays an O(1) dict lookup.

Tiering:
  1. Exact join on normalized title (the ~18% that already match) — also used
     as a regression anchor: every exact pair MUST survive the fuzzy stage.
  2. Fuzzy join (difflib ratio) on aggressively normalized Zolai titles, with a
     high acceptance threshold. A MIDI that can't clear the bar is left OUT of
     the map — better to fall through to an English MIDI than mis-map a hymn.
  3. Greedy unique assignment: one slug is claimed by at most one MIDI (highest
     score wins), so two MIDIs can't both point at the same hymn.

    python workers/tools/align_td_midi.py [--threshold 0.85] [--dry-run]
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
from difflib import SequenceMatcher

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import storage  # noqa: E402
from hymns_td import all_hymns  # noqa: E402

_OUT = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                    "data", "td_midi_slug_map.json")

# Mirrors seed_tedim_midi.norm_title / strategy._norm_title: letters-only, upper.
def _norm_title(name: str) -> str:
    return re.sub(r"[^A-Z]", "", name.upper())[:80]


def normalize_zolai(text: str) -> str:
    """Aggressive transliteration normalization for fuzzy comparison.

    Collapses the predictable orthographic shifts between the two title sources
    (vowel lengthening, nasal finals) so genuinely-equal titles align even when
    spelled differently. Used ONLY for matching — never for storage keys.
    """
    t = text.lower().strip()
    t = re.sub(r"[^a-z0-9]", "", t)        # drop spaces, hyphens, apostrophes
    t = re.sub(r"aa+", "a", t)
    t = re.sub(r"ii+", "i", t)
    t = re.sub(r"uu+", "u", t)
    t = re.sub(r"ee+", "e", t)
    t = re.sub(r"oo+", "o", t)
    t = t.replace("ng", "n")               # standardize nasal finals
    return t


def _seeded_midi_norms() -> set[str]:
    """The set of MIDI render keys present, as their <NORM> title component."""
    out: set[str] = set()
    for key in storage.list_keys("hymns_td/inst/"):
        if key.endswith(".mp3"):
            out.add(key.rsplit("/", 1)[-1][: -len(".mp3")])
    return out


def build_map(threshold: float) -> tuple[dict[str, str], dict]:
    hymns = all_hymns()
    # Precompute normalized forms once.
    lib = [(h["slug"], _norm_title(h["title"]), normalize_zolai(h["title"])) for h in hymns]
    midi_norms = sorted(_seeded_midi_norms())

    # Tier 1 — exact normalized-title join (regression anchor).
    by_exact = {norm: slug for slug, norm, _ in lib}
    exact = {m: by_exact[m] for m in midi_norms if m in by_exact}

    # Score every remaining MIDI against every hymn; keep candidates >= threshold.
    claimed: set[str] = set(exact.values())
    candidates: list[tuple[float, str, str]] = []  # (score, midi_norm, slug)
    for m in midi_norms:
        if m in exact:
            continue
        mz = normalize_zolai(m)
        for slug, _, hz in lib:
            score = SequenceMatcher(None, mz, hz).ratio()
            if score >= threshold:
                candidates.append((score, m, slug))

    # Tier 3 — greedy unique assignment: highest score first, one slug per MIDI
    # and one MIDI per slug.
    candidates.sort(reverse=True)
    fuzzy: dict[str, str] = {}
    used_midi: set[str] = set()
    for score, m, slug in candidates:
        if m in used_midi or slug in claimed:
            continue
        fuzzy[m] = slug
        used_midi.add(m)
        claimed.add(slug)

    mapping = {**exact, **fuzzy}

    # Regression check: every exact pair must still resolve to itself.
    anchor_ok = all(mapping.get(m) == by_exact[m] for m in exact)

    stats = {
        "midi_seeded": len(midi_norms),
        "library_size": len(hymns),
        "exact_matches": len(exact),
        "fuzzy_added": len(fuzzy),
        "total_mapped": len(mapping),
        "coverage_pct": round(100 * len(mapping) / max(1, len(midi_norms)), 1),
        "regression_anchor_ok": anchor_ok,
        "threshold": threshold,
    }
    return mapping, stats


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--threshold", type=float, default=0.85,
                    help="minimum difflib ratio to accept a fuzzy match (default 0.85)")
    ap.add_argument("--dry-run", action="store_true", help="print stats; do not write the map")
    args = ap.parse_args()

    mapping, stats = build_map(args.threshold)
    print(json.dumps(stats, indent=2))
    if not stats["regression_anchor_ok"]:
        print("REGRESSION: an exact match was overwritten by a fuzzy one — aborting.", file=sys.stderr)
        sys.exit(1)
    if args.dry_run:
        print("(dry-run) not writing", _OUT)
        return
    with open(_OUT, "w", encoding="utf-8") as fh:
        json.dump(mapping, fh, ensure_ascii=False, indent=2, sort_keys=True)
    print(f"wrote {len(mapping)} pairs → {_OUT}")


if __name__ == "__main__":
    main()
