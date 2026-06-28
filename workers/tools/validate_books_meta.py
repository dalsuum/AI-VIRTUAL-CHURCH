"""Validate the canonical Bible knowledge model (workers/data/books_meta.json).

Turns the ontology into a *verified artifact*: run in CI / pre-merge so a bad
edit can't land. Enforces the structural invariants from
docs/BIBLE_KNOWLEDGE_MODEL_INVARIANTS.md. Exits non-zero on any error.

    python workers/tools/validate_books_meta.py

Also importable: validate(meta_dict) -> list[str] of error messages ([] = ok).
"""

from __future__ import annotations

import json
import os
import re
import sys

_DATA = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "data")
_FILE = os.path.join(_DATA, "books_meta.json")

_CATEGORIES = {
    "pentateuch", "history", "wisdom", "major_prophets", "minor_prophets",
    "gospels", "acts", "pauline_epistles", "general_epistles", "apocalyptic",
}
_TESTAMENTS = {"old_testament", "new_testament"}
_ID_RE = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*$")
# Fields every book must define (reserved ones may be empty/null but must exist).
_REQUIRED = [
    "id", "number", "canonical_order", "testament", "category", "english_name",
    "localized_name", "localized_short_name", "aliases", "keywords", "themes",
    "pronunciation",
]
# Optional Phase-1 expansion fields; if present, types are checked.
_LIST_FIELDS = ["aliases", "keywords", "themes", "major_events", "key_people",
                "key_places", "related_books", "messianic_references", "prophecy_links"]


def validate(data: dict) -> list[str]:
    errs: list[str] = []

    if data.get("schema_version") != 1:
        errs.append(f"schema_version must be 1, got {data.get('schema_version')!r}")
    if not data.get("generated_at"):
        errs.append("generated_at is missing")

    books = data.get("books", {})
    if len(books) != 66:
        errs.append(f"expected 66 books, got {len(books)}")

    numbers, orders, alias_owner = {}, [], {}
    for bid, b in books.items():
        where = f"book {bid!r}"
        for f in _REQUIRED:
            if f not in b:
                errs.append(f"{where}: missing required field {f!r}")
        if b.get("id") != bid:
            errs.append(f"{where}: key != id ({b.get('id')!r})")
        if not _ID_RE.match(bid or ""):
            errs.append(f"{where}: id is not a stable lowercase slug")

        n = b.get("number")
        if not isinstance(n, int) or not (1 <= n <= 66):
            errs.append(f"{where}: number must be 1..66, got {n!r}")
        else:
            numbers.setdefault(n, []).append(bid)
        if b.get("canonical_order") != n:
            errs.append(f"{where}: canonical_order must equal number")
        orders.append(b.get("canonical_order"))

        if b.get("testament") not in _TESTAMENTS:
            errs.append(f"{where}: testament must be one of {_TESTAMENTS}")
        elif isinstance(n, int):
            want = "old_testament" if n <= 39 else "new_testament"
            if b["testament"] != want:
                errs.append(f"{where}: testament {b['testament']} != {want} for number {n}")
        if b.get("category") not in _CATEGORIES:
            errs.append(f"{where}: category must be a canonical id in {_CATEGORIES}")
        if not (b.get("english_name") or "").strip():
            errs.append(f"{where}: english_name is empty")

        for f in _LIST_FIELDS:
            if f in b and not isinstance(b[f], list):
                errs.append(f"{where}: {f} must be a list")

        # Aliases: non-empty, unique within the book AND unique across books
        # (a shared alias would make reference/search ambiguous).
        aliases = b.get("aliases") or []
        norm = [re.sub(r"\s+", " ", a.replace(".", "").strip().lower()) for a in aliases]
        if not aliases:
            errs.append(f"{where}: aliases is empty")
        if len(norm) != len(set(norm)):
            errs.append(f"{where}: duplicate aliases within book")
        for a in norm:
            if a in alias_owner and alias_owner[a] != bid:
                errs.append(f"alias {a!r} shared by {alias_owner[a]!r} and {bid!r}")
            alias_owner[a] = bid

    # Canon completeness: numbers 1..66 exactly once; canonical_order is 1..66.
    dups = {n: ids for n, ids in numbers.items() if len(ids) > 1}
    if dups:
        errs.append(f"duplicate book numbers: {dups}")
    missing = sorted(set(range(1, 67)) - set(numbers))
    if missing:
        errs.append(f"missing book numbers: {missing}")
    if sorted(o for o in orders if isinstance(o, int)) != list(range(1, 67)):
        errs.append("canonical_order is not a gap-free 1..66")

    # Referential integrity: every related_books / *_links id must be a real book id.
    ids = set(books)
    for bid, b in books.items():
        for ref in (b.get("related_books") or []):
            if ref not in ids:
                errs.append(f"book {bid!r}: related_books -> unknown id {ref!r}")

    return errs


def main() -> int:
    with open(_FILE, encoding="utf-8") as fh:
        data = json.load(fh)
    errs = validate(data)
    if errs:
        print(f"books_meta.json: {len(errs)} problem(s):", file=sys.stderr)
        for e in errs:
            print(f"  - {e}", file=sys.stderr)
        return 1
    print(f"books_meta.json OK — {len(data['books'])} books, schema_version {data['schema_version']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
