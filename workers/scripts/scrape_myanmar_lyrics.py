#!/usr/bin/env python3
"""
Scrape Myanmar worship lyrics from myanmarpraiseandworshipsongs.com
Filters to Unicode-only, deduplicates against hymns_my.json, saves to
workers/data/myanmar_lyrics_collection.json
"""

import json
import re
import sys
import time
from pathlib import Path

import requests
from bs4 import BeautifulSoup

OUTPUT = Path("/opt/ai-church/workers/data/myanmar_lyrics_collection.json")
EXISTING_HYMNS = Path("/opt/ai-church/workers/data/hymns_my.json")
DELAY = 0.8  # seconds between requests

CATEGORY_URLS = [
    ("က",           "http://www.myanmarpraiseandworshipsongs.com/p/blog-page_5.html"),
    ("ခ",           "http://www.myanmarpraiseandworshipsongs.com/p/blog-page_85.html"),
    ("ဂ",           "http://www.myanmarpraiseandworshipsongs.com/p/gahnge.html"),
    ("င",           "http://www.myanmarpraiseandworshipsongs.com/p/ngah.html"),
    ("စ",           "http://www.myanmarpraiseandworshipsongs.com/p/sahlone.html"),
    ("ဆ",           "http://www.myanmarpraiseandworshipsongs.com/p/sahleng.html"),
    ("ဇ",           "http://www.myanmarpraiseandworshipsongs.com/p/zahguay.html"),
    ("ည",           "http://www.myanmarpraiseandworshipsongs.com/p/blog-page_15.html"),
    ("ဒ",           "http://www.myanmarpraiseandworshipsongs.com/p/blog-page_23.html"),
    ("ဌ",           "https://www.myanmarpraiseandworshipsongs.com/p/blog-page_707.html"),
    ("ဓ",           "http://www.myanmarpraiseandworshipsongs.com/p/blog-page_62.html"),
    ("တ",           "http://www.myanmarpraiseandworshipsongs.com/p/thawombu_5.html"),
    ("ထ",           "http://www.myanmarpraiseandworshipsongs.com/p/blog-page_17.html"),
    ("န",           "http://www.myanmarpraiseandworshipsongs.com/p/blog-page_27.html"),
    ("ပ",           "http://www.myanmarpraiseandworshipsongs.com/p/blog-page_88.html"),
    ("ဖ",           "http://www.myanmarpraiseandworshipsongs.com/p/bahohtot.html"),
    ("ဗ",           "http://www.myanmarpraiseandworshipsongs.com/p/blog-page_60.html"),
    ("ဘ",           "http://www.myanmarpraiseandworshipsongs.com/p/bahgon.html"),
    ("မ",           "http://www.myanmarpraiseandworshipsongs.com/p/ma.html"),
    ("ယ",           "http://www.myanmarpraiseandworshipsongs.com/p/yahpehlet.html"),
    ("ရ",           "http://www.myanmarpraiseandworshipsongs.com/p/blog-page_40.html"),
    ("လ",           "http://www.myanmarpraiseandworshipsongs.com/p/blog-page_10.html"),
    ("သ",           "http://www.myanmarpraiseandworshipsongs.com/p/tah.html"),
    ("ဟ",           "http://www.myanmarpraiseandworshipsongs.com/p/hah.html"),
    ("ဝ",           "http://www.myanmarpraiseandworshipsongs.com/p/wah.html"),
    ("အ",           "http://www.myanmarpraiseandworshipsongs.com/p/ah.html"),
    ("ဧ",           "http://www.myanmarpraiseandworshipsongs.com/p/ee.html"),
    ("Burmese Hymn","https://www.myanmarpraiseandworshipsongs.com/p/burmese-hymn.html"),
    ("Christmas",   "https://www.myanmarpraiseandworshipsongs.com/p/christmas-songs.html"),
    ("Children",    "https://www.myanmarpraiseandworshipsongs.com/p/myanmar-christian-children-songs.html"),
]

# Zawgyi uses U+1060–U+1096 as glyph variants not valid in standard Myanmar Unicode
_ZAWGYI_RANGE = set(range(0x1060, 0x1097))


def is_zawgyi(text: str) -> bool:
    return any(ord(c) in _ZAWGYI_RANGE for c in text)


def has_myanmar(text: str) -> bool:
    return any('က' <= c <= '႟' for c in text)


def strip_chords(text: str) -> str:
    # Bracketed chord annotations: [D], [Am7], [F#m/C], etc.
    text = re.sub(
        r'\[[A-Ga-g][b#]?(?:m|M|maj|min|dim|aug|sus|add|dom)?\d*(?:/[A-G][b#]?)?\]',
        '', text
    )
    # Section labels alone on a line
    text = re.sub(
        r'^\s*(?:V\d*|Verse\s*\d*|Cho(?:rus)?|Bridge|Intro|Outro|Solo'
        r'|Pre-?Chorus|B\d*|P\d*|E\d*|Ending)\s*$',
        '', text, flags=re.MULTILINE | re.IGNORECASE
    )
    # Drop lines that are purely chord names (no Myanmar chars)
    kept = []
    for line in text.split('\n'):
        if has_myanmar(line):
            kept.append(line)
        elif re.match(r'^\s*(?:[A-Ga-g][b#]?\S*\s*)+$', line.strip()) and line.strip():
            pass  # chord-only line, drop
        else:
            kept.append(line)
    text = '\n'.join(kept)
    text = re.sub(r'\n{3,}', '\n\n', text)
    return text.strip()


def normalize_title(title: str) -> str:
    title = re.sub(r'^[\d\s.\-)(၊]+', '', title)
    title = re.sub(r'\s+', ' ', title)
    return title.strip().lower()


def make_session() -> requests.Session:
    s = requests.Session()
    s.headers['User-Agent'] = (
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 '
        '(KHTML, like Gecko) Chrome/124.0 Safari/537.36'
    )
    return s


def fetch_song_links(session: requests.Session, url: str) -> list[tuple[str, str]]:
    try:
        resp = session.get(url, timeout=15)
        resp.raise_for_status()
    except Exception as e:
        print(f"  [WARN] category fetch failed: {e}")
        return []
    soup = BeautifulSoup(resp.text, 'html.parser')
    links = []
    seen = set()
    for a in soup.find_all('a', href=True):
        href: str = a['href']
        text = a.get_text(strip=True)
        if (href in seen or
                not has_myanmar(text) or
                not any(d in href for d in
                        ('myanmarpraiseandworshipsongs.com', 'mmpnws.blogspot.com'))):
            continue
        # Skip category/navigation links (p/blog-page, p/myanmar, etc.) that aren't song pages
        seen.add(href)
        links.append((text, href))
    return links


def fetch_song(session: requests.Session, title: str, url: str) -> dict | None:
    try:
        resp = session.get(url, timeout=15)
        resp.raise_for_status()
    except Exception as e:
        print(f"  [WARN] song fetch failed {url}: {e}")
        return None

    soup = BeautifulSoup(resp.text, 'html.parser')

    # Prefer the post body / article content
    content = (
        soup.find('div', class_='post-body') or
        soup.find('div', class_='entry-content') or
        soup.find('div', id=re.compile(r'post-body')) or
        soup.find('article') or
        soup.find('div', class_='post')
    )
    if not content:
        return None

    raw = content.get_text('\n')
    lyrics = strip_chords(raw)

    if not has_myanmar(lyrics) or is_zawgyi(lyrics):
        return None

    # Use page h-tag title if the link text lacks Myanmar
    song_title = title
    if not has_myanmar(song_title):
        for tag in soup.find_all(['h1', 'h2', 'h3']):
            t = tag.get_text(strip=True)
            if has_myanmar(t):
                song_title = t
                break

    return {
        "title": song_title.strip(),
        "lyrics": lyrics,
        "source": "myanmarpraiseandworshipsongs.com",
        "url": url,
    }


def load_existing_titles() -> set[str]:
    seen: set[str] = set()
    if EXISTING_HYMNS.exists():
        data = json.loads(EXISTING_HYMNS.read_text())
        for h in data.get('hymns', []):
            seen.add(normalize_title(h.get('title', '')))
    if OUTPUT.exists():
        for entry in json.loads(OUTPUT.read_text()):
            seen.add(normalize_title(entry.get('title', '')))
    return seen


def main(test_mode: bool = False):
    session = make_session()

    print("Loading existing titles for deduplication…")
    seen_titles = load_existing_titles()
    print(f"  {len(seen_titles)} titles already known")

    categories = CATEGORY_URLS[:3] if test_mode else CATEGORY_URLS

    all_song_urls: set[str] = set()
    songs: list[dict] = []
    skipped_dup = 0
    skipped_zawgyi = 0
    errors = 0

    for letter, cat_url in categories:
        print(f"\n[{letter}] {cat_url}")
        links = fetch_song_links(session, cat_url)
        print(f"  → {len(links)} song links found")
        time.sleep(DELAY)

        for song_title, song_url in links:
            if song_url in all_song_urls:
                continue
            all_song_urls.add(song_url)

            norm = normalize_title(song_title)
            if norm in seen_titles:
                skipped_dup += 1
                continue

            song = fetch_song(session, song_title, song_url)
            time.sleep(DELAY)

            if song is None:
                skipped_zawgyi += 1
                print(f"  ✗ skipped (zawgyi/empty): {song_title[:40]}")
            else:
                seen_titles.add(normalize_title(song['title']))
                songs.append(song)
                print(f"  ✓ {song['title'][:50]}  ({len(song['lyrics'])} chars)")

    print(f"\n{'='*60}")
    print(f"Collected : {len(songs)} new songs")
    print(f"Dup skip  : {skipped_dup}")
    print(f"Zawgyi/err: {skipped_zawgyi}")

    OUTPUT.write_text(json.dumps(songs, ensure_ascii=False, indent=2), encoding='utf-8')
    print(f"Saved → {OUTPUT}")


if __name__ == '__main__':
    test = '--test' in sys.argv
    main(test_mode=test)
