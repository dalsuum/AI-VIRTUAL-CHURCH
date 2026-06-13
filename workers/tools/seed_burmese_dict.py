"""
Seed the local English–Myanmar dictionary from soeminnminn/EngMyanDictionary.

Downloads the SQLite database from GitHub, copies it to workers/data/, then
pre-extracts the church/worship vocabulary into burmese_church_vocab.json.

Run once at deploy time (or whenever you want to refresh):
    cd workers && python tools/seed_burmese_dict.py

The generated files (gitignored, ~35 MB total):
    workers/data/eng_myan_dict.db          — full 21,984-entry SQLite dictionary
    workers/data/burmese_church_vocab.json — 73 pre-extracted worship terms
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sqlite3
import subprocess
import tempfile

_REPO_URL = "https://github.com/soeminnminn/EngMyanDictionary.git"
_DB_SUBPATH = "app/src/main/assets/database/dictionary.db"

_DATA_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "data")
_DB_DEST = os.path.join(_DATA_DIR, "eng_myan_dict.db")
_VOCAB_DEST = os.path.join(_DATA_DIR, "burmese_church_vocab.json")

CHURCH_TERMS = [
    "grace", "mercy", "salvation", "prayer", "faith", "hope", "peace",
    "blessing", "love", "holy", "worship", "sermon", "gospel", "heaven",
    "soul", "spirit", "lord", "church", "bible", "baptism", "amen",
    "sin", "praise", "glory", "righteous", "repent", "forgive", "eternal",
    "almighty", "mighty", "sacred", "divine", "covenant",
    "compassion", "heart", "truth", "light", "life", "death", "resurrection",
    "miracle", "prophet", "angel", "kingdom", "throne",
    "humble", "sacrifice", "offering", "testimony", "promise", "comfort",
    "deliver", "redeem", "sanctify", "bless", "heal", "shepherd", "lamb",
    "cross", "trust", "strength", "refuge", "anointed",
    "thanksgiving", "gratitude", "scripture", "congregation",
    "benediction", "intercession", "trinity",
    "joy", "sorrow", "suffering", "patience", "burden",
    "victory", "power", "pure",
]


def _strip_html(html: str) -> str:
    text = re.sub(r"<[^>]+>", " ", html)
    text = re.sub(r"&[a-z#0-9]+;", " ", text)
    text = re.sub(r"/[^/]{1,40}/", " ", text)
    segments = re.split(r"\s{2,}|\n", text)
    parts = []
    for seg in segments:
        seg = seg.strip()
        if len(re.findall(r"[က-႟]", seg)) > 3:
            seg = re.sub(r"^[၀-၉\d]+[။,. ]+", "", seg).strip()
            seg = re.sub(r"[A-Za-z]{5,}.*", "", seg).strip().rstrip(".,/ ")
            if len(re.findall(r"[က-႟]", seg)) > 2:
                parts.append(seg)
    return " / ".join(parts[:3])


def _extract_vocab(db_path: str) -> dict[str, str]:
    conn = sqlite3.connect(db_path)
    c = conn.cursor()
    vocab: dict[str, str] = {}
    for term in CHURCH_TERMS:
        c.execute(
            "SELECT word, definition FROM dictionary WHERE stripword = ? ORDER BY _id LIMIT 1",
            (term.lower(),),
        )
        row = c.fetchone()
        if row:
            phrase = _strip_html(row[1] or "")
            if not phrase:
                raw = re.sub(r"<[^>]+>", "", row[1] or "")
                m = re.search(r"[က-႟][^\n<>]{5,}", raw)
                phrase = m.group(0)[:80].split("❍")[0].strip() if m else ""
            if phrase:
                vocab[term] = phrase
    conn.close()
    return vocab


def _download_db(force: bool = False) -> None:
    if os.path.exists(_DB_DEST) and not force:
        print(f"Dictionary already exists at {_DB_DEST} (use --force to re-download)")
        return

    os.makedirs(_DATA_DIR, exist_ok=True)
    with tempfile.TemporaryDirectory() as tmpdir:
        print(f"Cloning {_REPO_URL} (shallow) …")
        subprocess.run(
            ["git", "clone", "--depth=1", _REPO_URL, tmpdir],
            check=True,
        )
        src = os.path.join(tmpdir, _DB_SUBPATH)
        if not os.path.exists(src):
            raise FileNotFoundError(f"Expected DB at {src} inside the cloned repo")
        import shutil
        shutil.copy2(src, _DB_DEST)
        print(f"Copied dictionary DB → {_DB_DEST}")


def _seed_vocab(force: bool = False) -> None:
    if os.path.exists(_VOCAB_DEST) and not force:
        print(f"Church vocab already exists at {_VOCAB_DEST} (use --force to regenerate)")
        return
    if not os.path.exists(_DB_DEST):
        raise FileNotFoundError(f"Dictionary DB not found at {_DB_DEST} — run without --skip-db first")
    vocab = _extract_vocab(_DB_DEST)
    with open(_VOCAB_DEST, "w", encoding="utf-8") as f:
        json.dump(vocab, f, ensure_ascii=False, indent=2, sort_keys=True)
    print(f"Extracted {len(vocab)} church terms → {_VOCAB_DEST}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--force", action="store_true", help="Re-download/regenerate even if files exist")
    parser.add_argument("--skip-db", action="store_true", help="Skip DB download (use existing)")
    args = parser.parse_args()

    if not args.skip_db:
        _download_db(force=args.force)
    _seed_vocab(force=args.force)
    print("Done.")
