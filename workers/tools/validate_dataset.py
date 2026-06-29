"""Validate the Burmese sermon corpus (storage/knowledge/sermons_my/raw/).

Checks:
  - Total sermons
  - Per source (denomination)
  - Duplicate SHA256 (by text field)
  - Missing title / author / date
  - Invalid JSON
  - UTF-8 validation
  - Bible reference parsing success (references field non-empty where expected)
  - Canonical ID success (id slug format)
  - Average sermon length (chars in text field)
  - Top Bible books referenced

    python workers/tools/validate_dataset.py [--raw-dir PATH]

Exits 0 on clean corpus (warnings only), 1 if any fatal issues found.
"""

from __future__ import annotations

import argparse
import collections
import hashlib
import json
import os
import re
import sys
from pathlib import Path

_ROOT = Path(__file__).resolve().parent.parent.parent
_DEFAULT_RAW = _ROOT / "backend" / "storage" / "knowledge" / "sermons_my" / "raw"

_ID_RE = re.compile(r"^[0-9a-f]{12}$")


def _sha256(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8", errors="replace")).hexdigest()


def validate(raw_dir: Path) -> int:
    files = sorted(raw_dir.glob("*.json"))
    if not files:
        print(f"ERROR: no .json files found in {raw_dir}", file=sys.stderr)
        return 1

    total = 0
    invalid_json: list[str] = []
    utf8_errors: list[str] = []
    missing_title: list[str] = []
    missing_author: list[str] = []
    missing_date: list[str] = []
    bad_id: list[str] = []
    sha_seen: dict[str, str] = {}   # sha → first file id
    duplicates: list[tuple[str, str]] = []

    per_source: dict[str, int] = collections.Counter()
    ref_parsed = 0
    ref_total = 0
    lengths: list[int] = []
    book_counter: dict[str, int] = collections.Counter()

    for fp in files:
        # UTF-8 check — read raw bytes first
        try:
            raw_bytes = fp.read_bytes()
            raw_bytes.decode("utf-8")
        except UnicodeDecodeError:
            utf8_errors.append(fp.name)
            continue

        # JSON parse
        try:
            rec = json.loads(raw_bytes)
        except json.JSONDecodeError:
            invalid_json.append(fp.name)
            continue

        total += 1
        rid = rec.get("id", fp.stem)

        # Canonical ID format
        if not _ID_RE.match(str(rid)):
            bad_id.append(fp.name)

        # Required metadata
        if not (rec.get("title") or "").strip():
            missing_title.append(rid)
        if not (rec.get("author") or "").strip():
            missing_author.append(rid)
        if not (rec.get("date") or "").strip():
            missing_date.append(rid)

        # Per-source counts
        source = (rec.get("denomination") or "unknown").strip() or "unknown"
        per_source[source] += 1

        # Duplicate detection via SHA256 of text
        text = rec.get("text") or ""
        lengths.append(len(text))
        if text:
            sha = _sha256(text)
            if sha in sha_seen:
                duplicates.append((rid, sha_seen[sha]))
            else:
                sha_seen[sha] = rid

        # Bible reference success
        refs = rec.get("references") or []
        if isinstance(refs, list) and refs:
            ref_parsed += 1
        ref_total += 1

        # Top books
        for ref in refs:
            if isinstance(ref, str):
                book = ref.split()[0] if ref else ""
                if book:
                    book_counter[book] += 1
            elif isinstance(ref, dict):
                book = ref.get("id") or ref.get("book") or ""
                if book:
                    book_counter[book] += 1

    # --- Report ---
    print(f"\n{'='*60}")
    print(f"  Burmese Sermon Corpus Validation Report")
    print(f"{'='*60}")
    print(f"\nTotal sermons read:       {total}")
    print(f"  Invalid JSON (skipped): {len(invalid_json)}")
    print(f"  UTF-8 errors (skipped): {len(utf8_errors)}")

    print(f"\nPer source (denomination):")
    for src, cnt in sorted(per_source.items(), key=lambda x: -x[1]):
        print(f"  {src:<30} {cnt:>5}")

    print(f"\nDuplicate SHA256 pairs:   {len(duplicates)}")
    for a, b in duplicates[:10]:
        print(f"  {a}  ==  {b}")
    if len(duplicates) > 10:
        print(f"  ... and {len(duplicates) - 10} more")

    warn_count = 0

    def warn(label: str, items: list, limit: int = 5) -> None:
        nonlocal warn_count
        if not items:
            return
        warn_count += 1
        print(f"\nWARN — {label} ({len(items)}):")
        for x in items[:limit]:
            print(f"  {x}")
        if len(items) > limit:
            print(f"  ... and {len(items) - limit} more")

    warn("Missing title", missing_title)
    warn("Missing author", missing_author)
    warn("Missing date", missing_date)
    warn("Non-standard ID format", bad_id)

    if ref_total:
        pct = 100 * ref_parsed / ref_total
        print(f"\nBible refs parsed:        {ref_parsed}/{ref_total} ({pct:.1f}%)")
    else:
        print("\nBible refs parsed:        n/a (no records)")

    if lengths:
        avg = sum(lengths) / len(lengths)
        print(f"Average sermon length:    {avg:.0f} chars  "
              f"(min {min(lengths)}, max {max(lengths)})")
    else:
        print("Average sermon length:    n/a")

    print(f"\nTop 10 Bible books referenced:")
    if book_counter:
        for book, cnt in book_counter.most_common(10):
            print(f"  {book:<20} {cnt:>5}")
    else:
        print("  (none parsed)")

    # --- Fatal check ---
    fatal = bool(invalid_json or utf8_errors or duplicates)
    print(f"\n{'='*60}")
    if fatal:
        issues = []
        if invalid_json:
            issues.append(f"{len(invalid_json)} invalid JSON")
        if utf8_errors:
            issues.append(f"{len(utf8_errors)} UTF-8 errors")
        if duplicates:
            issues.append(f"{len(duplicates)} duplicate(s)")
        print(f"FAIL — {', '.join(issues)}", file=sys.stderr)
        return 1

    quality = "OK" if warn_count == 0 else f"OK with {warn_count} warning(s)"
    print(f"  {quality} — {total} sermons, {len(per_source)} source(s)")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--raw-dir", default=str(_DEFAULT_RAW), help="Path to raw/ corpus directory")
    args = parser.parse_args()
    return validate(Path(args.raw_dir))


if __name__ == "__main__":
    raise SystemExit(main())
