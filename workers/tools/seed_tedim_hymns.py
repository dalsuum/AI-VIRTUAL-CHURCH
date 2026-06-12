"""Seed the Tedim (ZBC Labu Lui) hymn library used by the Tedim music source.

Run once per machine, like seed_hymns.py (the English hymnal seeder) and the
Myanmar importer. It collects the ZBC Labu Lui hymnal — ~470 Tedim translations
of classic hymns — from labusaal.com, where the community publishes them:

    https://labusaal.com/artist/zbc-labu-lui/    (index of every hymn)
    https://labusaal.com/lyrics/<slug>/          (one page per hymn)

For every hymn it extracts:
    * the Tedim title and the English original title (in parentheses) — the
      English title is also the mood-tagging signal: these are all classic
      hymns whose themes are known ("It Is Well With My Soul" -> grieving/
      comfort), far more reliable than keyword-guessing the Tedim text;
    * the Tedim verses (Key lines and print chrome stripped);
    * the YouTube embed id, when the page has one — the Tedim music strategy
      prefers this embed (real Tedim singing, zero AI credit).

Output: workers/data/hymns_td.json, the file hymns_td.py serves at service time.

The collection runs on YOUR server, at YOUR deploy time — the same way
seed_hymns.py pulls Open Hymnal — so the data is always as fresh as the site
and distribution stays between you and the publisher. ZBC Labu Lui is a Zomi
Baptist Convention hymnal (1984 reprint of mission-era translations); confirm
permission with ZBC / labusaal.com before promoting to production.

    pip install requests beautifulsoup4
    python workers/tools/seed_tedim_hymns.py            # full run (~470 pages, polite 0.7s delay)
    python workers/tools/seed_tedim_hymns.py --limit 5  # smoke test
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
import time

import requests
from bs4 import BeautifulSoup

INDEX_URL = "https://labusaal.com/artist/zbc-labu-lui/"
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "data", "hymns_td.json")
CACHE_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), ".labusaal-cache")
HEADERS = {"User-Agent": "aivirtual.church seeder (contact: site admin)"}
DELAY = 0.7  # be a polite guest

# Not worship hymns — national/patriotic songs in the hymnal's back pages.
EXCLUDE_SLUGS = {"kawlgam-lapi", "i-khamtung-gam"}

# English-original-title keywords -> mood tags (the app's mood vocabulary).
# Matched lowercase against the parenthesized English title.
_MOOD_RULES: list[tuple[str, set[str]]] = [
    ("praise",      {"joyful", "praise", "grateful", "joy"}),
    ("joy",         {"joyful", "joy", "happy"}),
    ("rejoice",     {"joyful", "joy"}),
    ("thank",       {"grateful"}),
    ("bless",       {"grateful", "grace"}),
    ("grace",       {"grateful", "grace", "hopeful"}),
    ("assurance",   {"anxious", "assurance", "hopeful"}),
    ("trust",       {"anxious", "assurance", "fear"}),
    ("faith",       {"anxious", "assurance", "hopeful"}),
    ("comfort",     {"grieving", "comfort", "anxious"}),
    ("well with my soul", {"grieving", "comfort", "anxious", "loss"}),
    ("abide",       {"grieving", "comfort", "lonely"}),
    ("shelter",     {"anxious", "comfort", "fear"}),
    ("storm",       {"anxious", "comfort", "fear"}),
    ("peace",       {"anxious", "peace", "comfort"}),
    ("rest",        {"anxious", "peace", "comfort"}),
    ("prayer",      {"seeking", "prayer"}),
    ("pray",        {"seeking", "prayer"}),
    ("hope",        {"hopeful", "hope"}),
    ("heaven",      {"hopeful", "hope"}),
    ("home",        {"hopeful", "comfort"}),
    ("love",        {"grateful", "love"}),
    ("shepherd",    {"comfort", "assurance", "anxious"}),
    ("lead",        {"seeking", "journey", "anxious"}),
    ("guide",       {"seeking", "journey", "anxious"}),
    ("saviour",     {"seeking", "hopeful"}),
    ("savior",      {"seeking", "hopeful"}),
    ("salvation",   {"seeking", "hopeful", "assurance"}),
    ("redeem",      {"grateful", "hopeful"}),
    ("cross",       {"seeking", "grace"}),
    ("holy",        {"praise", "seeking"}),
    ("christmas",   {"joyful", "joy"}),
    ("noel",        {"joyful", "joy"}),
    ("angels",      {"joyful", "joy"}),
    ("morning",     {"joyful", "grateful"}),
    ("evening",     {"peace", "comfort"}),
    ("disconsolate", {"grieving", "comfort", "loss"}),
    ("sorrow",      {"grieving", "comfort", "loss"}),
    ("weep",        {"grieving", "comfort"}),
    ("wings",       {"comfort", "anxious", "assurance"}),
    ("hide",        {"comfort", "anxious", "fear"}),
    ("refuge",      {"comfort", "anxious", "fear"}),
    ("rock",        {"assurance", "anxious"}),
    ("foundation",  {"assurance", "anxious", "hope"}),
    ("promise",     {"assurance", "hopeful"}),
    ("written there", {"assurance", "hopeful"}),
    ("victory",     {"hopeful", "joyful"}),
    ("triumph",     {"hopeful", "joyful"}),
    ("story",       {"seeking", "grateful"}),
    ("calling",     {"seeking"}),
    ("calls",       {"seeking"}),
    ("come",        {"seeking"}),
    ("surrender",   {"seeking", "prayer"}),
    ("need thee",   {"seeking", "anxious", "prayer"}),
    ("friend",      {"comfort", "lonely", "prayer"}),
    ("alone",       {"lonely", "comfort"}),
    ("crown",       {"praise", "joyful"}),
    ("hail",        {"praise", "joyful"}),
    ("worship",     {"praise", "seeking"}),
    ("glory",       {"praise", "joyful"}),
    ("sing",        {"joyful", "praise"}),
    ("song",        {"joyful", "praise"}),
]

# Tedim-vocabulary signals, matched lowercase against the TEDIM title — a second
# net for hymns whose English title carries no keyword.
_MOOD_RULES_TD: list[tuple[str, set[str]]] = [
    ("lungdam",   {"joyful", "grateful", "joy"}),     # joy / thanks
    ("phat",      {"praise", "joyful"}),              # praise
    ("dahna",     {"grieving", "comfort", "loss"}),   # sorrow
    ("thupha",    {"grateful", "grace", "hopeful"}),  # blessing
    ("itna",      {"grateful", "love"}),              # love
    ("muang",     {"anxious", "assurance", "fear"}),  # trust
    ("um in",     {"anxious", "assurance"}),          # believe/trust
    ("thu nge",   {"seeking", "prayer"}),             # prayer (thu ngetna)
    ("thunget",   {"seeking", "prayer"}),
    ("thumna",    {"seeking", "prayer"}),
    ("vantung",   {"hopeful", "hope"}),               # heaven
    ("kha siangtho", {"seeking"}),                    # Holy Spirit
    ("hehpihna",  {"grateful", "grace"}),             # mercy/grace
    ("gupna",     {"seeking", "hopeful"}),            # salvation
    ("hong gum",  {"seeking", "hopeful", "assurance"}),
    ("nopna",     {"joyful", "peace"}),               # gladness
    ("nuam",      {"joyful", "peace", "comfort"}),    # pleasant/glad
    ("kilemna",   {"peace", "anxious"}),              # peace
    ("singlamteh", {"seeking", "grace"}),             # the cross
]

# Page chrome that is NOT lyric text on a labusaal lyric page.
_STOP_HEADINGS = re.compile(
    r"^(other songs|related lyrics|added by|share|write a comment|no comments|advertisement|video|leave a comment)",
    re.I,
)
_KEY_LINE = re.compile(r"^\s*key\s+[A-G][#b]?m?\s*$", re.I)
_YT_EMBED = re.compile(r"youtube\.com/embed/([A-Za-z0-9_-]{6,})")
_TITLE_SPLIT = re.compile(r"^(?P<td>.*?)\s*\((?P<en>[^()]*(?:\([^()]*\)[^()]*)?)\)\s*(?P<tune>\d+(?:st|nd|rd|th)\s+Tune)?\s*$", re.I)


def _moods(english_title: str, tedim_title: str = "") -> list[str]:
    tags: set[str] = {"default"}
    en = english_title.lower()
    for keyword, mood_tags in _MOOD_RULES:
        if keyword in en:
            tags |= mood_tags
    td = tedim_title.lower()
    for keyword, mood_tags in _MOOD_RULES_TD:
        if keyword in td:
            tags |= mood_tags
    return sorted(tags)


def _get(url: str, session: requests.Session) -> str:
    """Fetch with a tiny on-disk cache so a re-run never re-downloads."""
    os.makedirs(CACHE_DIR, exist_ok=True)
    key = re.sub(r"[^a-z0-9-]", "_", url.lower())[-120:]
    path = os.path.join(CACHE_DIR, key)
    if os.path.exists(path):
        return open(path, encoding="utf-8").read()
    resp = session.get(url, headers=HEADERS, timeout=30)
    resp.raise_for_status()
    open(path, "w", encoding="utf-8").write(resp.text)
    time.sleep(DELAY)
    return resp.text


def _index(session: requests.Session) -> list[str]:
    """Every /lyrics/<slug>/ URL in the ZBC Labu Lui index, in page order."""
    soup = BeautifulSoup(_get(INDEX_URL, session), "html.parser")
    urls: list[str] = []
    for a in soup.select("a[href*='/lyrics/']"):
        href = a.get("href", "").split("#")[0]
        if href and href not in urls:
            urls.append(href)
    return urls


def _parse_lyric_page(html: str, url: str) -> dict | None:
    soup = BeautifulSoup(html, "html.parser")

    h1 = soup.find("h1")
    if not h1:
        return None
    raw_title = h1.get_text(" ", strip=True)
    m = _TITLE_SPLIT.match(raw_title)
    title_td = (m.group("td") if m else raw_title).strip(" ,")
    title_en = (m.group("en") if m else "").strip()

    yt = _YT_EMBED.search(html)
    youtube_id = yt.group(1) if yt else None

    # The lyric body is the run of paragraph-ish blocks after the LYRIC heading
    # and before the next section heading. Walk the document in order; keep
    # text once we're inside the lyric, stop at the first chrome heading.
    lines: list[str] = []
    in_lyric = False
    for el in soup.find_all(["h1", "h2", "h3", "h4", "p", "em", "strong"]):
        text = el.get_text("\n", strip=True)
        if not text:
            continue
        if el.name in ("h2", "h3", "h4"):
            label = text.lower()
            if label.startswith("lyric"):
                in_lyric = True
                continue
            if in_lyric and _STOP_HEADINGS.match(label):
                break
            continue
        if not in_lyric or el.name != "p":
            continue
        if _STOP_HEADINGS.match(text) or text.lower() in ("print",):
            continue
        for ln in text.split("\n"):
            ln = ln.strip()
            if not ln or _KEY_LINE.match(ln):
                continue
            lines.append(ln)
        lines.append("")  # paragraph break

    lyrics = re.sub(r"\n{3,}", "\n\n", "\n".join(lines)).strip()
    if len(lyrics) < 40:
        return None

    slug = url.rstrip("/").rsplit("/", 1)[-1]
    return {
        "slug": f"td-{slug}",
        "source": "zbc-labu-lui",
        "title": title_td,
        "title_en": title_en,            # the English original this translates
        "lyrics": lyrics,
        "youtube_id": youtube_id,        # preferred playback: real Tedim singing
        "url": url,                      # provenance
        "moods": _moods(title_en, title_td),
    }


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int, default=0, help="only seed the first N hymns (smoke test)")
    args = ap.parse_args()

    session = requests.Session()
    urls = [u for u in _index(session) if u.rstrip("/").rsplit("/", 1)[-1] not in EXCLUDE_SLUGS]
    if args.limit:
        urls = urls[: args.limit]
    print(f"{len(urls)} hymn pages to collect from {INDEX_URL}")

    hymns, with_yt, failed = [], 0, []
    for i, url in enumerate(urls, 1):
        try:
            hymn = _parse_lyric_page(_get(url, session), url)
        except Exception as exc:  # noqa: BLE001 — collect what we can, report the rest
            failed.append((url, str(exc)))
            continue
        if not hymn:
            failed.append((url, "no lyric body found"))
            continue
        hymns.append(hymn)
        with_yt += bool(hymn["youtube_id"])
        if i % 25 == 0:
            print(f"  {i}/{len(urls)} … ({with_yt} with YouTube)")

    out = {
        "info": {
            "name": "ZBC Labu Lui (Tedim)",
            "origin": INDEX_URL,
            "license_note": "Zomi Baptist Convention hymnal (1984). Confirm "
                            "permission with ZBC / labusaal.com for production use.",
            "counts": {"hymns": len(hymns), "with_youtube": with_yt},
        },
        "hymns": hymns,
    }
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    json.dump(out, open(OUT, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(f"wrote {OUT}: {len(hymns)} hymns, {with_yt} with YouTube embeds, {len(failed)} failed")
    for url, why in failed[:10]:
        print(f"  FAILED {url}: {why}")


if __name__ == "__main__":
    main()
