"""
Collects Myanmar worship lyrics from OpenLyrics XMLs and a Blogspot index.
Enforces Unicode, removes guitar chords, deduplicates, and outputs to JSON.

Usage (from project root /opt/ai-church):
    pip install requests beautifulsoup4
    git clone https://github.com/dalsuum/openlyrics.git ./openlyrics
    python workers/tools/collect_myanmar_lyrics.py
"""

import os
import re
import sys
import time
import json
import unicodedata
import xml.etree.ElementTree as ET
import requests
from bs4 import BeautifulSoup

BLOG_INDEX_URL = "https://www.myanmarpraiseandworshipsongs.com/"

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.join(SCRIPT_DIR, "..", "..")
OUTPUT_FILE = os.path.join(SCRIPT_DIR, "..", "data", "myanmar_lyrics_collection.json")
DEFAULT_OPENLYRICS_PATH = os.path.join(PROJECT_ROOT, "openlyrics")

# Regex to strip guitar chords, commonly found above lyrics
_CHORD_LINE = re.compile(r"^\s*(?:[A-G][#b]?(?:m|maj|min|dim|aug|sus|add)?[0-9]*(?:/[A-G][#b]?)?[\s.|()-]*)+\s*$")
_HAS_MYANMAR = re.compile(r"[\u1000-\u109f]")
_CTRL = re.compile(r"[\x00-\x08\x0b\x0c\x0e-\x1f]")

def clean_lyrics(raw_text: str) -> str:
    """Normalizes Unicode, strips control characters, and removes chord-only lines."""
    text = _CTRL.sub("", unicodedata.normalize("NFC", raw_text or ""))
    lines = []
    for line in text.splitlines():
        line = line.strip()
        if not line:
            continue
        # Skip lines that are just English/Latin letters (often chords or English metadata)
        # if the rest of the song is Burmese.
        if _CHORD_LINE.match(line) and not _HAS_MYANMAR.search(line):
            continue
        lines.append(line)
    
    out = "\n".join(lines)
    out = re.sub(r"\n{3,}", "\n\n", out)
    return out.strip()

def deduplicate_key(lyrics: str) -> str:
    """Creates a normalized string to identify duplicate lyrics (ignores spacing/punctuation)."""
    # Remove all whitespace and punctuation for a strict comparison
    return re.sub(r"[^\u1000-\u109fA-Za-z0-9]", "", lyrics)

def parse_openlyrics_repo(repo_path: str) -> list[dict]:
    """Walks a directory of OpenLyrics XML files and extracts titles and lyrics."""
    print(f"Parsing OpenLyrics from: {repo_path}...")
    songs = []
    if not os.path.exists(repo_path):
        print(f"Warning: OpenLyrics path '{repo_path}' not found. Skipping.")
        return songs

    ns = {"ol": "http://openlyrics.info/namespace/2009/song"}
    
    for root_dir, _, files in os.walk(repo_path):
        for file in files:
            if not file.endswith(".xml"):
                continue
            
            path = os.path.join(root_dir, file)
            try:
                tree = ET.parse(path)
                root = tree.getroot()
                
                # Extract title
                title_elem = root.find(".//ol:titles/ol:title", ns)
                if title_elem is None:
                    continue
                title = title_elem.text
                
                # Extract lyrics
                lyrics_parts = []
                for lines_elem in root.findall(".//ol:lyrics/ol:verse/ol:lines", ns):
                    # Join text and <br/> tags
                    lines_text = "".join(lines_elem.itertext())
                    lyrics_parts.append(lines_text)
                
                raw_lyrics = "\n\n".join(lyrics_parts)
                cleaned_lyrics = clean_lyrics(raw_lyrics)
                
                if _HAS_MYANMAR.search(cleaned_lyrics):
                    songs.append({
                        "title": clean_lyrics(title),
                        "lyrics": cleaned_lyrics,
                        "source": "openlyrics"
                    })
            except Exception as e:
                print(f"Failed to parse XML {file}: {e}")
                
    print(f"Found {len(songs)} Myanmar songs in OpenLyrics repo.")
    return songs

def scrape_blogspot(url: str) -> list[dict]:
    """Scrapes all posts instantly using the Blogger JSON feed."""
    base_url = url.split("/p/")[0].rstrip("/") if "/p/" in url else url.rstrip("/")
    feed_url = f"{base_url}/feeds/posts/default?alt=json&max-results=1000"
    print(f"Scraping Blogspot feed: {feed_url}...")
    songs = []
    
    try:
        resp = requests.get(feed_url, timeout=30)
        resp.raise_for_status()
        data = resp.json()
        entries = data.get("feed", {}).get("entry", [])
        print(f"Found {len(entries)} posts in the feed.")

        for entry in entries:
            title_text = entry.get("title", {}).get("$t", "Unknown Title")
            html_content = entry.get("content", {}).get("$t", "")
            
            # Convert HTML to text
            soup = BeautifulSoup(html_content, "html.parser")
            raw_lyrics = soup.get_text(separator="\n", strip=True)
            cleaned_lyrics = clean_lyrics(raw_lyrics)
            
            # Only include if it has Myanmar text and is long enough to actually be a song
            # (filters out index posts that are just links/alphabets)
            if _HAS_MYANMAR.search(cleaned_lyrics) and len(cleaned_lyrics) > 50:
                post_url = next((l["href"] for l in entry.get("link", []) if l["rel"] == "alternate"), "")
                songs.append({
                    "title": clean_lyrics(title_text),
                    "lyrics": cleaned_lyrics,
                    "source": "blogspot",
                    "url": post_url
                })
    except Exception as e:
        print(f"Failed to scrape blogspot index: {e}")
        
    return songs

def main():
    openlyrics_path = sys.argv[1] if len(sys.argv) > 1 else DEFAULT_OPENLYRICS_PATH
    
    all_songs = []
    all_songs.extend(parse_openlyrics_repo(openlyrics_path))
    all_songs.extend(scrape_blogspot(BLOG_INDEX_URL))
    
    # Deduplicate
    print("\nDeduplicating...")
    seen_keys = set()
    unique_songs = []
    
    for song in all_songs:
        key = deduplicate_key(song["lyrics"])
        if key and key not in seen_keys:
            seen_keys.add(key)
            unique_songs.append(song)
            
    # Save
    os.makedirs(os.path.dirname(OUTPUT_FILE), exist_ok=True)
    with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
        json.dump(unique_songs, f, ensure_ascii=False, indent=2)
        
    print(f"\nSuccess! Wrote {len(unique_songs)} unique Myanmar Unicode songs to {OUTPUT_FILE}")

if __name__ == "__main__":
    main()