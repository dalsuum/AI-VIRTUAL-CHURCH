"""Export Burmese sermon corpus to knowledge:ingest JSON format.

Reads raw/*.json from sermons_my and emits a single JSON array
[{id, text, metadata}] to storage/app/knowledge/sermons_my.json,
ready for:

    php artisan knowledge:ingest sermon storage/app/knowledge/sermons_my.json \\
        --chunker=text --lang=my

    python workers/tools/export_sermons_my.py [--raw-dir PATH] [--out FILE]
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

_ROOT = Path(__file__).resolve().parent.parent.parent
_DEFAULT_RAW = _ROOT / "backend" / "storage" / "knowledge" / "sermons_my" / "raw"
_DEFAULT_OUT = _ROOT / "backend" / "storage" / "app" / "knowledge" / "sermons_my.json"


def export_corpus(raw_dir: Path, out_path: Path) -> int:
    files = sorted(raw_dir.glob("*.json"))
    if not files:
        print(f"ERROR: no .json files in {raw_dir}", file=sys.stderr)
        return 1

    docs: list[dict] = []
    skipped = 0
    for fp in files:
        try:
            raw = fp.read_bytes().decode("utf-8")
            rec = json.loads(raw)
        except Exception as exc:
            print(f"  skip {fp.name}: {exc}", file=sys.stderr)
            skipped += 1
            continue

        text = (rec.get("text") or "").strip()
        if not text:
            skipped += 1
            continue

        docs.append({
            "id": f"sermon_my:{rec.get('id', fp.stem)}",
            "text": text,
            "metadata": {
                "source": "sermon",
                "language": "my",
                "reference": rec.get("url") or "",
                "permissions": ["public"],
                "title": rec.get("title") or "",
                "author": rec.get("author") or "",
                "date": rec.get("date") or "",
                "denomination": rec.get("denomination") or "",
                "summary": rec.get("summary") or "",
                "bible_refs": rec.get("references") or [],
                "keywords": rec.get("keywords") or [],
            },
        })

    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(json.dumps(docs, ensure_ascii=False, indent=None), encoding="utf-8")

    print(f"Exported {len(docs)} sermons → {out_path}  (skipped {skipped})")
    print(f"\nNext step:")
    print(f"  php artisan knowledge:ingest sermon storage/app/knowledge/sermons_my.json --chunker=text --lang=my")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--raw-dir", default=str(_DEFAULT_RAW))
    parser.add_argument("--out", default=str(_DEFAULT_OUT))
    args = parser.parse_args()
    return export_corpus(Path(args.raw_dir), Path(args.out))


if __name__ == "__main__":
    raise SystemExit(main())
