"""
English–Myanmar dictionary lookup (soeminnminn/EngMyanDictionary, ~22k entries).

Database: workers/data/eng_myan_dict.db  (SQLite, Myanmar Unicode)
Pre-extracted church vocabulary: workers/data/burmese_church_vocab.json

Usage:
    from myanmar_dict import lookup, church_vocab, CHURCH_VOCAB

    result = lookup("grace")        # DictEntry or None
    print(result.burmese)           # clean Myanmar text
    print(CHURCH_VOCAB["grace"])    # pre-extracted church definition
"""

import json
import os
import re
import sqlite3
from dataclasses import dataclass
from functools import lru_cache

_BASE = os.path.dirname(os.path.abspath(__file__))
_DB_PATH = os.path.join(_BASE, "data", "eng_myan_dict.db")
_VOCAB_PATH = os.path.join(_BASE, "data", "burmese_church_vocab.json")


@dataclass
class DictEntry:
    word: str
    title: str
    burmese: str      # cleaned Myanmar-script text only
    raw_html: str     # original HTML definition (for reference)


def _strip_html(html: str) -> str:
    """Remove HTML tags, entities, phonetics, and return clean Myanmar text."""
    if not html:
        return ""
    text = re.sub(r"<[^>]+>", " ", html)
    text = re.sub(r"&[a-z#0-9]+;", " ", text)
    # Remove IPA/phonetic blocks /.../ and romanizations
    text = re.sub(r"/[^/]{1,40}/", " ", text)
    # Keep Myanmar-dominant segments
    segments = re.split(r"\s{2,}|\n", text)
    myanmar_parts = []
    for seg in segments:
        seg = seg.strip()
        if len(re.findall(r"[က-႟]", seg)) > 3:
            # Strip leading number markers ၁။ ၂။
            seg = re.sub(r"^[၀-၉\d]+[။,. ]+", "", seg).strip()
            # Cut off at first long English word (likely an example sentence)
            seg = re.sub(r"[A-Za-z]{5,}.*", "", seg).strip().rstrip(".,/ ")
            if len(re.findall(r"[က-႟]", seg)) > 2:
                myanmar_parts.append(seg)
    return " / ".join(myanmar_parts[:3])


@lru_cache(maxsize=2048)
def lookup(word: str) -> DictEntry | None:
    """
    Look up an English word in the dictionary.
    Returns the first matching DictEntry, or None if not found.
    Caches results in memory for repeated lookups.
    """
    if not os.path.exists(_DB_PATH):
        return None
    conn = sqlite3.connect(_DB_PATH)
    c = conn.cursor()
    try:
        c.execute(
            "SELECT word, title, definition FROM dictionary "
            "WHERE stripword = ? ORDER BY _id LIMIT 1",
            (word.lower().strip(),),
        )
        row = c.fetchone()
    finally:
        conn.close()

    if not row:
        return None

    burmese = _strip_html(row[2])
    if not burmese:
        # Fallback: grab first Myanmar sequence directly
        raw = re.sub(r"<[^>]+>", "", row[2] or "")
        m = re.search(r"[က-႟][^\n<>]{5,}", raw)
        burmese = m.group(0)[:120].split("❍")[0].strip() if m else ""

    return DictEntry(
        word=row[0],
        title=row[1] or "",
        burmese=burmese,
        raw_html=row[2] or "",
    )


def lookup_many(words: list[str]) -> dict[str, str]:
    """Look up multiple words; returns {word: burmese_text} for found entries."""
    result = {}
    for w in words:
        entry = lookup(w)
        if entry and entry.burmese:
            result[w] = entry.burmese
    return result


# ---------------------------------------------------------------------------
# Pre-extracted church vocabulary — loaded once at import time
# ---------------------------------------------------------------------------
try:
    with open(_VOCAB_PATH, encoding="utf-8") as _f:
        CHURCH_VOCAB: dict[str, str] = json.load(_f)
except FileNotFoundError:
    CHURCH_VOCAB = {}


def church_vocab() -> dict[str, str]:
    """Return the pre-extracted church vocabulary mapping {english: burmese}."""
    return CHURCH_VOCAB


def church_vocab_prompt_snippet(max_terms: int = 30) -> str:
    """
    Return a compact vocabulary reference suitable for injecting into a system
    prompt. Picks the most worship-relevant terms first.
    Format: "english = burmese; ..."
    """
    priority = [
        "grace", "mercy", "salvation", "prayer", "faith", "hope", "peace",
        "blessing", "love", "holy", "worship", "sermon", "gospel", "heaven",
        "soul", "spirit", "lord", "church", "bible", "amen", "sin", "praise",
        "glory", "eternal", "compassion", "truth", "light", "life", "joy",
        "sorrow", "sacrifice", "thanksgiving", "repent", "righteous",
    ]
    terms = []
    seen = set()
    for k in priority:
        if k in CHURCH_VOCAB and k not in seen:
            terms.append(f"{k} = {CHURCH_VOCAB[k][:60]}")
            seen.add(k)
        if len(terms) >= max_terms:
            break
    for k, v in CHURCH_VOCAB.items():
        if k not in seen and len(terms) < max_terms:
            terms.append(f"{k} = {v[:60]}")
    return "; ".join(terms)
