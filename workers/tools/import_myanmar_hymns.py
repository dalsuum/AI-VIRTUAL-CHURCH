"""Import the Burmese hymn library from github.com/dalsuum/myanmar-hymns.

Converts the Flutter app's two XML songbooks into one clean JSON data file the
worker can serve at service time:

    assets/songs/hymns.xml   410 classic hymnal songs (Burmese, numbered)
    assets/songs/modern.xml  515 modern songs (Burmese lyrics + chord lines)
        -> workers/data/hymns_my.json

What this does, and why:

  * The source XML reuses odd tag names (<publication> per song, the lyrics live
    in <publisher>) — we map them to honest names (id/title/lyrics).
  * modern.xml is NOT well-formed XML: it contains raw control characters
    (\x0c form feeds) that break strict parsers. We strip the C0 range first.
  * Modern songs interleave guitar-chord lines with the words. Chord lines are
    useless for on-screen worship display and poison TTS/Suno input, so lines
    that are chords-only (or "Key : G" markers) are removed.
  * Each hymn is tagged with English mood keywords (the same vocabulary the
    rest of the app uses — hymns.select matches the worshipper's mood against
    these) using Burmese keyword heuristics on the title + lyrics. Every hymn
    also carries "default" so selection can never fail.

Run from the repo root with a checkout of myanmar-hymns next to it:

    python workers/tools/import_myanmar_hymns.py ../myanmar-hymns
"""

from __future__ import annotations

import json
import os
import re
import sys
import unicodedata
import xml.etree.ElementTree as ET

OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "data", "hymns_my.json")

# C0 control characters (except \t \n \r) — present in modern.xml, illegal in XML 1.0.
_CTRL = re.compile(r"[\x00-\x08\x0b\x0c\x0e-\x1f]")

# A line that is only chord symbols / fret junk, e.g. "G   D7  Em" or "C/G".
_CHORD_LINE = re.compile(r"^\s*(?:[A-G][#b]?(?:m|maj|min|dim|aug|sus|add)?[0-9]*(?:/[A-G][#b]?)?[\s.|()-]*)+\s*$")
_KEY_LINE = re.compile(r"^\s*key\s*[:=]", re.I)

# Burmese keyword -> English mood tags (the vocabulary tasks/hymns.select uses).
_MOOD_RULES: list[tuple[str, set[str]]] = [
    ("ချီးမွမ်း", {"joyful", "praise", "grateful", "joy"}),       # praise
    ("ကျေးဇူး",   {"grateful", "grace"}),                          # thanks / grace
    ("ဝမ်းမြောက်", {"joyful", "joy", "happy"}),                    # rejoice
    ("နှစ်သိမ့်",  {"grieving", "comfort", "anxious", "loss"}),    # comfort
    ("ဆုတောင်း",  {"seeking", "prayer"}),                          # prayer
    ("ပဌနာ",      {"seeking", "prayer"}),                          # supplication
    ("မျှော်လင့်", {"hopeful", "hope"}),                            # hope
    ("မေတ္တာ",     {"grateful", "love"}),                           # love
    ("ငြိမ်သက်",  {"anxious", "peace", "comfort"}),                # peace
    ("ကယ်တင်",   {"seeking", "hopeful", "assurance"}),            # salvation
    ("ယုံကြည်",   {"anxious", "assurance", "hopeful", "fear"}),    # faith / trust
    ("ကောင်းကင်", {"hopeful", "hope"}),                            # heaven
    ("နာမတော်",   {"praise", "joyful"}),                           # His name
    ("ဝိညာဉ်",    {"seeking"}),                                    # spirit
    ("ခွန်အား",    {"anxious", "hope", "assurance"}),               # strength
    ("သခင်ယေရှု", {"default"}),                                    # Lord Jesus (neutral)
]

_HAS_MYANMAR = re.compile(r"[\u1000-\u109f]")


def _clean_lyrics(raw: str, *, drop_chords: bool) -> str:
    text = _CTRL.sub("", unicodedata.normalize("NFC", raw or ""))
    lines = []
    for line in text.splitlines():
        line = line.rstrip()
        if drop_chords and (_KEY_LINE.match(line) or (_CHORD_LINE.match(line) and not _HAS_MYANMAR.search(line))):
            continue
        lines.append(line)
    out = "\n".join(lines)
    out = re.sub(r"\n{3,}", "\n\n", out)          # at most one blank line
    out = re.sub(r"[ \t]{2,}", " ", out)
    return out.strip()


def _moods(title: str, lyrics: str) -> list[str]:
    hay = f"{title}\n{lyrics}"
    tags: set[str] = {"default"}
    for keyword, mood_tags in _MOOD_RULES:
        if keyword in hay:
            tags |= mood_tags
    return sorted(tags)


def _parse(path: str, *, source: str, drop_chords: bool) -> list[dict]:
    data = _CTRL.sub("", open(path, encoding="utf-8").read())
    root = ET.fromstring(data)
    hymns = []
    for position, song in enumerate(root, start=1):  # <publication> per song
        # ~130 hymnal entries carry an EMPTY <id> in the source XML; the slug is
        # the audio-cache key, so it must be unique — fall back to the song's
        # 1-based position in the file, which is stable across re-imports.
        sid = (song.findtext("id") or "").strip() or f"p{position}"
        title = _CTRL.sub("", unicodedata.normalize("NFC", (song.findtext("title") or "").strip()))
        lyrics = _clean_lyrics(song.findtext("publisher") or "", drop_chords=drop_chords)
        if not title or len(lyrics) < 30:  # skip empty / fragment entries
            continue
        # Burmese services should sing Burmese: keep only songs whose lyrics are
        # actually in Myanmar script (modern.xml carries ~73 English-only songs).
        if not _HAS_MYANMAR.search(lyrics):
            continue
        hymns.append({
            "slug": f"{source}-{sid}",
            "source": source,            # "hymnal" (classic 410) | "modern"
            "number": int(sid) if sid.isdigit() else None,
            "title": title,
            "lyrics": lyrics,
            "moods": _moods(title, lyrics),
        })
    return hymns


def main() -> None:
    repo = sys.argv[1] if len(sys.argv) > 1 else "../myanmar-hymns"
    songs_dir = os.path.join(repo, "assets", "songs")

    hymnal = _parse(os.path.join(songs_dir, "hymns.xml"), source="hymnal", drop_chords=False)
    modern = _parse(os.path.join(songs_dir, "modern.xml"), source="modern", drop_chords=True)

    out = {"info": {"name": "Myanmar Hymns", "origin": "github.com/dalsuum/myanmar-hymns",
                    "counts": {"hymnal": len(hymnal), "modern": len(modern)}},
           "hymns": hymnal + modern}

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, "w", encoding="utf-8") as fh:
        json.dump(out, fh, ensure_ascii=False, indent=1)
    print(f"wrote {OUT}: {len(hymnal)} hymnal + {len(modern)} modern songs")


if __name__ == "__main__":
    main()
