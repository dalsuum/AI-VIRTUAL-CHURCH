"""One-off research helper: find public-domain SUNG recordings for each catalog
hymn on the Internet Archive's 78rpm collection.

Why these constraints make the result safely public domain in the US:
  * collection:78rpm  -> George Blood transfers of 78rpm discs.
  * subject:Vocal     -> sung performance (not a band/organ instrumental).
  * year <= 1925      -> the SOUND RECORDING is PD (Music Modernization Act: pre-1923
                         PD since 2022; 1923/24/25 entered PD in 2024/25/26). The hymn
                         TEXT/TUNE is 19th-c. or earlier, so both layers are PD.

Run it, eyeball the ranked output, and paste the chosen identifier/file/url/year
into hymns.py as each hymn's `recording`. It does NOT modify the catalog — sung
recordings need a human to confirm the title really matches and the take is clean.

    python workers/source_recordings.py            # all hymns
    python workers/source_recordings.py amazing-grace it-is-well   # a subset
"""

from __future__ import annotations

import json
import os
import sys
import urllib.parse

import requests

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from hymns import HYMNS  # noqa: E402

# Search phrase per hymn slug — the bare, distinctive part of the title that the
# 78rpm cataloguers actually used (no parentheticals, no trailing clauses).
SEARCH = {
    "amazing-grace": "amazing grace",
    "it-is-well": "it is well with my soul",
    "be-still-my-soul": "be still my soul",
    "abide-with-me": "abide with me",
    "nearer-my-god": "nearer my god to thee",
    "come-ye-disconsolate": "come ye disconsolate",
    "what-a-friend": "what a friend we have in jesus",
    "guide-me": "guide me o thou great jehovah",
    "how-firm-a-foundation": "how firm a foundation",
    "my-hope-is-built": "my hope is built",
    "joyful-joyful": "joyful joyful we adore thee",
    "now-thank-we": "now thank we all our god",
    "praise-to-the-lord": "praise to the lord almighty",
    "all-creatures": "all creatures of our god and king",
    "to-god-be-the-glory": "to god be the glory",
    "holy-holy-holy": "holy holy holy",
    "crown-him": "crown him with many crowns",
    "be-thou-my-vision": "be thou my vision",
    "come-thou-fount": "come thou fount",
    "blessed-assurance": "blessed assurance",
    "rock-of-ages": "rock of ages",
    "pass-me-not": "pass me not o gentle savior",
    "i-need-thee": "i need thee every hour",
}

SEARCH_URL = "https://archive.org/advancedsearch.php"
META_URL = "https://archive.org/metadata/{id}"
DOWNLOAD = "https://archive.org/download/{id}/{file}"


# Surname(s) that should appear in a genuine recording's credits, to confirm the
# take is the RIGHT hymn (author and/or composer). Rejects same-word-different-hymn
# false positives, e.g. "Holy Ghost with Light Divine" for "Holy, Holy, Holy".
VERIFY = {
    "amazing-grace": ["newton"], "it-is-well": ["spafford", "bliss"],
    "be-still-my-soul": ["sibelius", "schlegel", "borthwick"],
    "abide-with-me": ["lyte", "monk"], "nearer-my-god": ["adams", "mason"],
    "come-ye-disconsolate": ["moore", "webbe"], "what-a-friend": ["scriven", "converse"],
    "guide-me": ["williams", "hughes"], "how-firm-a-foundation": ["rippon"],
    "my-hope-is-built": ["mote", "bradbury"], "joyful-joyful": ["van dyke", "beethoven"],
    "now-thank-we": ["rinkart", "cruger"], "praise-to-the-lord": ["neander"],
    "all-creatures": ["francis", "assisi"], "to-god-be-the-glory": ["crosby", "doane"],
    "holy-holy-holy": ["heber", "dykes"], "crown-him": ["bridges", "elvey"],
    "be-thou-my-vision": ["byrne", "hull"], "come-thou-fount": ["robinson", "wyeth"],
    "blessed-assurance": ["crosby", "knapp"], "rock-of-ages": ["toplady", "hastings"],
    "pass-me-not": ["crosby", "doane"], "i-need-thee": ["hawks", "lowry"],
}

# Singer signals (a sung performance) vs purely-instrumental ones (reject when no
# singer signal is present).
_VOCAL = ("vocal", "tenor", "soprano", "baritone", "contralto", "bass ", "mezzo",
          "quartet", "quartette", "choir", "chorus", "duet", "sings", "sung", "singer")
_INSTRUMENTAL = ("band", "orchestra solo", "organ solo", "instrumental", "xylophone",
                 "chimes", "whistling", "cornet solo", "piano solo", "violin solo")


def _search(phrase: str) -> list[dict]:
    q = f'title:({phrase}) AND collection:78rpm AND year:[1900 TO 1925]'
    params = {
        "q": q,
        "fl[]": ["identifier", "title", "year", "creator"],
        "rows": 12,
        "sort[]": "year asc",
        "output": "json",
    }
    r = requests.get(SEARCH_URL, params=params, timeout=40)
    r.raise_for_status()
    return r.json()["response"]["docs"]


def _meta(identifier: str) -> dict:
    r = requests.get(META_URL.format(id=identifier), timeout=40)
    r.raise_for_status()
    j = r.json()
    mp3 = next((f["name"] for f in j.get("files", []) if f["name"].lower().endswith(".mp3")), None)
    m = j.get("metadata", {})
    blob = " ".join(str(m.get(k, "")) for k in ("subject", "description", "notes", "creator")).lower()
    return {"mp3": mp3, "blob": blob}


def _title_match(phrase: str, title: str) -> float:
    t = (title or "").lower()
    words = list(dict.fromkeys(phrase.split()))  # dedupe so "holy holy holy" -> {holy}
    return sum(1 for w in words if w in t) / max(1, len(words))


def main() -> None:
    wanted = set(sys.argv[1:])
    hymns = [h for h in HYMNS if not wanted or h["slug"] in wanted]
    out: dict[str, dict] = {}

    for hymn in hymns:
        slug = hymn["slug"]
        phrase = SEARCH.get(slug, hymn["title"].lower())
        verifiers = VERIFY.get(slug, [])
        print(f"\n=== {slug}  ({hymn['title']}) ===", flush=True)
        try:
            docs = _search(phrase)
        except Exception as exc:  # noqa: BLE001
            print(f"  search failed: {exc}", flush=True)
            continue

        ndist = len(dict.fromkeys(phrase.split()))  # distinctive words in the title
        cands = []
        for d in docs:
            tm = _title_match(phrase, d.get("title", ""))
            if tm < 0.6:
                continue
            meta = _meta(d["identifier"])
            if not meta["mp3"]:
                continue
            blob = meta["blob"]
            vocal = any(w in blob for w in _VOCAL)
            instr = any(w in blob for w in _INSTRUMENTAL)
            author_ok = any(v in blob for v in verifiers) if verifiers else False
            # Accept a take as the right hymn when EITHER the author/composer is named
            # in the credits, OR the full title matches on >=2 distinctive words (safe
            # for unique titles like "How Firm a Foundation"; the author rule still
            # guards one-word-ambiguous titles like "Holy" from "The Holy City").
            title_ok = tm > 0.999 and ndist >= 2
            score = round(tm + 2 * author_ok + title_ok + vocal - (instr and not vocal), 2)
            cands.append((score, (author_ok or title_ok), vocal, instr, d, meta["mp3"]))
            print(f"  [{score:>4}] {d.get('year')} auth={int(author_ok)} title={int(title_ok)} "
                  f"voc={int(vocal)} inst={int(instr)} | {str(d.get('title'))[:30]:30} | {str(d.get('creator'))[:28]}", flush=True)

        # Accept only credible takes: right hymn confirmed (author or strong title) and
        # a singer present.
        good = sorted((c for c in cands if c[1] and c[2]), key=lambda c: c[0], reverse=True)
        if good:
            _, _, _, _, d, mp3 = good[0]
            chosen = {
                "identifier": d["identifier"], "file": mp3, "year": d.get("year"),
                "performer": d.get("creator"), "title": d.get("title"),
                "url": DOWNLOAD.format(id=d["identifier"], file=urllib.parse.quote(mp3)),
            }
            out[slug] = chosen
            print(f"  -> CHOSEN: {chosen['year']} {chosen['performer']}\n     {chosen['url']}", flush=True)
        else:
            print("  -> none verified (sung mode will fall back to instrumental)", flush=True)

    print("\n\n===== JSON mapping (review, then fold into hymns.py) =====", flush=True)
    print(json.dumps(out, indent=2), flush=True)


if __name__ == "__main__":
    main()
