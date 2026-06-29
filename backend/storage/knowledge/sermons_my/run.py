#!/usr/bin/env python3
"""Crawl Burmese sermon sites and build a clean AI training dataset.

One command does everything:

    python run.py

It recursively discovers sermon pages on the configured sources, downloads each
once (resuming across runs), extracts and cleans the Burmese text, then writes a
raw archive, a metadata table, and three JSONL datasets (plain text, instruction
tuning, and auto-generated QA pairs).

Be a good citizen: robots.txt is honoured, requests are throttled, and failures
are retried with backoff. You are responsible for having the right to use and
redistribute the crawled content before training or publishing a model on it.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import re
import sys
import time
import unicodedata
from dataclasses import asdict, dataclass, field
from pathlib import Path
from urllib.parse import urljoin, urlparse
from urllib.robotparser import RobotFileParser

import pandas as pd
import requests
import trafilatura
from bs4 import BeautifulSoup
from readability import Document
from tqdm import tqdm

# --------------------------------------------------------------------------- #
# Configuration
# --------------------------------------------------------------------------- #
SOURCES = [
    "https://www.myanmar3am.com/p/plain-revelation-pr.html",
    "https://minlwin.wordpress.com/",
]
ALLOWED_HOSTS = {"www.myanmar3am.com", "myanmar3am.com", "minlwin.wordpress.com"}

USER_AGENT = "SermonDatasetBot/1.0 (+respectful crawler; contact site owner)"
REQUEST_DELAY = 2.0          # seconds between requests, per politeness
MAX_RETRIES = 3
RETRY_BACKOFF = 4.0          # seconds, multiplied by attempt number
CHECKPOINT_EVERY = 20        # pages
MIN_BODY_CHARS = 200         # shorter than this is treated as not-a-sermon

ROOT = Path(__file__).resolve().parent
RAW_DIR = ROOT / "raw"
DATASET_DIR = ROOT / "dataset"
STATE_FILE = ROOT / "crawl_state.json"
METADATA_CSV = ROOT / "metadata.csv"

# Myanmar Unicode block + common punctuation, used to detect Burmese text.
_MY_RANGE = re.compile(r"[က-႟ꩠ-ꩿꧠ-꧿]")
# Bible reference patterns in both English and Burmese book-name forms.
_BIBLE_REF = re.compile(
    r"(?:[1-3]\s?)?[A-Zက-႟][\wက-႟\.]*\s?\d{1,3}[:：]\d{1,3}(?:[-–]\d{1,3})?"
)
# Boilerplate link/heading text we never want to follow or keep.
_JUNK_LINK = re.compile(
    r"comment|share|facebook|twitter|whatsapp|subscribe|login|sign|tag/|label/"
    r"|feed|rss|privacy|contact|about|search|/page/|wp-login|#",
    re.I,
)

# Each source maps to the denomination/publisher that runs it (best-effort
# constant; far cheaper and more reliable than guessing per article).
_SOURCE_DENOMINATION = {
    "myanmar3am.com": "Myanmar 3AM",
    "minlwin.wordpress.com": "Min Lwin",
}

# Canonical Bible knowledge model, reused from workers/ so sermon references link
# to the same stable IDs the Bible reader uses. Resolved relative to this file;
# if the workers tree is absent (tool copied elsewhere) linking degrades to off.
_WORKERS_DATA = ROOT.parents[3] / "workers" / "data"
_MY_DIGITS = str.maketrans("၀၁၂၃၄၅၆၇၈၉", "0123456789")


def _load_bible_index() -> dict:
    """book-name (English or Burmese) -> {id, number, testament}."""
    index: dict[str, dict] = {}
    try:
        meta = json.loads((_WORKERS_DATA / "books_meta.json").read_text("utf-8"))["books"]
        by_number = {b["number"]: b for b in meta.values()}
    except Exception:
        return index

    def add(token: str, num: int) -> None:
        b = by_number.get(num)
        if token and b:
            # Normalize digits to ASCII so "၁ ယော" matches references the parser
            # has already digit-normalized ("1 ယော").
            key = token.strip().translate(_MY_DIGITS).lower()
            index[key] = {"id": b["id"], "number": num, "testament": b["testament"]}

    for b in meta.values():
        for alias in [b["english_name"], *b.get("aliases", [])]:
            add(alias, b["number"])
    # Burmese book names from the Judson 1835 edition (canonical 1-66 numbering).
    try:
        judson = json.loads((_WORKERS_DATA / "judson1835.json").read_text("utf-8"))["book"]
        for num_str, book in judson.items():
            info = book.get("info", {})
            for token in (info.get("name"), info.get("shortname")):
                if not token:
                    continue
                add(token, int(num_str))
                # Also index the bare form most writers use: drop the ရှင် Gospel
                # honorific and the ခရစ်ဝင်/ကျမ်း suffix (e.g. ရှင်ယောဟန်ခရစ်ဝင် -> ယောဟန်).
                bare = re.sub(r"^ရှင်", "", token.rstrip("။"))
                bare = re.sub(r"(ခရစ်ဝင်|ကျမ်း)$", "", bare)
                add(bare, int(num_str))
    except Exception:
        pass
    return index


_BIBLE_INDEX = _load_bible_index()


def link_references(raw_refs: list[str]) -> list[dict]:
    """Map raw reference strings to canonical Bible IDs. Unresolved book names
    are kept with id=None so nothing is silently dropped."""
    out, seen = [], set()
    for raw in raw_refs:
        m = re.match(r"\s*(.+?)\s*(\d+)\s*[:：]\s*(\d+(?:[-–]\d+)?)\s*$", raw.translate(_MY_DIGITS))
        if not m:
            continue
        book_token, chapter, verses = m.group(1).strip(), m.group(2), m.group(3)
        # Drop the period in abbreviations (Deut. / Gen. / 1 Cor.) — the alias
        # table is dotless.
        info = _BIBLE_INDEX.get(book_token.replace(".", "").strip().lower())
        key = (info["id"] if info else book_token.lower(), chapter, verses)
        if key in seen:
            continue
        seen.add(key)
        out.append({
            "raw": raw.strip(),
            "id": info["id"] if info else None,
            "number": info["number"] if info else None,
            "testament": info["testament"] if info else None,
            "chapter": int(chapter),
            "verses": verses,
        })
    return out


def extractive_summary(text: str, max_sentences: int = 3) -> str:
    """First few Burmese sentences (split on the ၊/။ sentence marks)."""
    sentences = [s.strip() for s in re.split(r"(?<=[။\.])\s+", text) if s.strip()]
    return " ".join(sentences[:max_sentences])


def heading_outline(text: str, limit: int = 12) -> list[str]:
    """Short stand-alone lines read as section headings (no trailing stop)."""
    out = []
    for line in text.split("\n"):
        line = line.strip(" \t-•*၁၂၃၄၅၆၇၈၉0123456789.")
        if 6 <= len(line) <= 80 and is_burmese(line) and not line.endswith(("။", ".")):
            out.append(line)
            if len(out) >= limit:
                break
    return out


def top_keywords(text: str, limit: int = 15) -> list[str]:
    """Most frequent multi-char Burmese tokens (cheap TF, no model)."""
    tokens = [t for t in re.findall(r"[က-႟]{3,}", text)]
    freq: dict[str, int] = {}
    for t in tokens:
        freq[t] = freq.get(t, 0) + 1
    return [w for w, _ in sorted(freq.items(), key=lambda kv: kv[1], reverse=True)[:limit]]


# --------------------------------------------------------------------------- #
# Records
# --------------------------------------------------------------------------- #
@dataclass
class Sermon:
    id: str = ""
    title: str = ""
    author: str = ""
    date: str = ""
    url: str = ""
    language: str = "my"
    denomination: str = ""
    series: str = ""
    tags: list = field(default_factory=list)
    # Raw reference strings as written in the sermon (English + Burmese forms).
    bible_references: list = field(default_factory=list)
    # Structured references linked to the canonical Bible knowledge model.
    references: list = field(default_factory=list)
    # Primary passage (first resolved reference), surfaced as flat fields.
    book: str = ""
    chapter: str = ""
    verses: str = ""
    testament: str = ""
    # Deterministic enrichment (Phase 2). Semantic fields left for a later LLM
    # pass are kept as empty placeholders so the schema is stable.
    summary: str = ""
    outline: list = field(default_factory=list)
    keywords: list = field(default_factory=list)
    topics: list = field(default_factory=list)
    people: list = field(default_factory=list)
    places: list = field(default_factory=list)
    doctrines: list = field(default_factory=list)
    applications: list = field(default_factory=list)
    prayer: str = ""
    text: str = ""


# --------------------------------------------------------------------------- #
# Crawl state (resume + dedupe)
# --------------------------------------------------------------------------- #
def load_state() -> dict:
    if STATE_FILE.exists():
        return json.loads(STATE_FILE.read_text(encoding="utf-8"))
    return {"visited": [], "sermons": [], "seen_hashes": []}


def save_state(state: dict) -> None:
    STATE_FILE.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf-8")


# --------------------------------------------------------------------------- #
# HTTP with robots.txt, throttling and retries
# --------------------------------------------------------------------------- #
_robots: dict[str, RobotFileParser] = {}
_session = requests.Session()
_session.headers.update({"User-Agent": USER_AGENT})


def allowed_by_robots(url: str) -> bool:
    host = urlparse(url).netloc
    rp = _robots.get(host)
    if rp is None:
        rp = RobotFileParser()
        rp.set_url(f"{urlparse(url).scheme}://{host}/robots.txt")
        try:
            rp.read()
        except Exception:
            # If robots.txt is unreachable, default to permissive but stay polite.
            rp.parse([])
        _robots[host] = rp
    return rp.can_fetch(USER_AGENT, url)


def fetch(url: str) -> str | None:
    if not allowed_by_robots(url):
        return None
    for attempt in range(1, MAX_RETRIES + 1):
        try:
            resp = _session.get(url, timeout=30)
            time.sleep(REQUEST_DELAY)
            if resp.status_code == 200 and resp.text:
                resp.encoding = resp.apparent_encoding or "utf-8"
                return resp.text
            if resp.status_code in (404, 410):
                return None
        except requests.RequestException:
            pass
        time.sleep(RETRY_BACKOFF * attempt)
    return None


# --------------------------------------------------------------------------- #
# Discovery
# --------------------------------------------------------------------------- #
def in_scope(url: str) -> bool:
    p = urlparse(url)
    if p.netloc not in ALLOWED_HOSTS:
        return False
    if _JUNK_LINK.search(url):
        return False
    if any(url.lower().endswith(ext) for ext in (".jpg", ".png", ".pdf", ".gif", ".zip")):
        return False
    return p.scheme in ("http", "https")


def discover_links(html: str, base_url: str) -> set[str]:
    """Return in-scope internal links (sermon pages, categories, pagination)."""
    soup = BeautifulSoup(html, "html.parser")
    links = set()
    for a in soup.find_all("a", href=True):
        href = urljoin(base_url, a["href"].split("#")[0]).rstrip("/")
        if href and in_scope(href):
            links.add(href)
    return links


# --------------------------------------------------------------------------- #
# Extraction + cleaning
# --------------------------------------------------------------------------- #
def normalize_my(text: str) -> str:
    text = unicodedata.normalize("NFC", text)
    text = re.sub(r"[ \t ]+", " ", text)
    text = re.sub(r"\n{3,}", "\n\n", text)
    return text.strip()


def is_burmese(text: str) -> bool:
    sample = text[:2000]
    my = len(_MY_RANGE.findall(sample))
    return my >= 30  # enough Myanmar codepoints to count as Burmese content


def extract_body(html: str, url: str) -> str:
    """Prefer trafilatura; fall back to readability; both strip boilerplate."""
    body = trafilatura.extract(
        html, include_comments=False, include_tables=False, favor_recall=True, url=url
    )
    if not body or len(body) < MIN_BODY_CHARS:
        try:
            summary_html = Document(html).summary()
            body = BeautifulSoup(summary_html, "html.parser").get_text("\n")
        except Exception:
            body = ""
    return normalize_my(body or "")


def meta(soup: BeautifulSoup, *names: str) -> str:
    for name in names:
        tag = soup.find("meta", attrs={"property": name}) or soup.find(
            "meta", attrs={"name": name}
        )
        if tag and tag.get("content"):
            return tag["content"].strip()
    return ""


def parse_sermon(html: str, url: str) -> Sermon | None:
    body = extract_body(html, url)
    if len(body) < MIN_BODY_CHARS or not is_burmese(body):
        return None

    soup = BeautifulSoup(html, "html.parser")
    title = ""
    if soup.title and soup.title.string:
        title = soup.title.string.strip()
    h1 = soup.find(["h1", "h2"])
    if h1 and h1.get_text(strip=True):
        title = h1.get_text(strip=True)
    title = normalize_my(title) or url

    author = meta(soup, "article:author", "author") or ""
    date = (
        meta(soup, "article:published_time", "datePublished")
        or (soup.find("time").get("datetime", "") if soup.find("time") else "")
    )
    tags = sorted(
        {
            a.get_text(strip=True)
            for a in soup.find_all("a", href=re.compile(r"(label|tag|category)/", re.I))
            if a.get_text(strip=True)
        }
    )
    refs = sorted(set(_BIBLE_REF.findall(body)))
    references = link_references(refs)
    primary = next((r for r in references if r["id"]), references[0] if references else None)

    return Sermon(
        id=hashlib.sha1(url.encode("utf-8")).hexdigest()[:12],
        title=title,
        author=author,
        date=date,
        url=url,
        denomination=_SOURCE_DENOMINATION.get(urlparse(url).netloc.replace("www.", ""), ""),
        series="Plain Revelation" if "plain-revelation" in url else "",
        tags=tags,
        bible_references=refs,
        references=references,
        book=primary["id"] or "" if primary else "",
        chapter=str(primary["chapter"]) if primary else "",
        verses=primary["verses"] if primary else "",
        testament=primary["testament"] or "" if primary else "",
        summary=extractive_summary(body),
        outline=heading_outline(body),
        keywords=top_keywords(body),
        text=body,
    )


# --------------------------------------------------------------------------- #
# QA generation
# --------------------------------------------------------------------------- #
def build_qa(s: Sermon) -> list[dict]:
    pairs = [
        {"question": f"{s.title} ဟောချက်၏ အဓိကအချက်မှာ အဘယ်နည်း။",
         "answer": s.text[:1200]},
    ]
    if s.bible_references:
        pairs.append({
            "question": "ဤတရားဒေသနာတွင် ကိုးကားထားသော သမ္မာကျမ်းစာ အပိုဒ်များမှာ အဘယ်နည်း။",
            "answer": "၊ ".join(s.bible_references),
        })
    # Heading-driven QA: each sub-heading line becomes a "what does X teach" pair.
    for line in s.text.split("\n"):
        line = line.strip()
        if 6 <= len(line) <= 80 and is_burmese(line) and not line.endswith(("။", ".")):
            pairs.append({"question": f"{line} ဆိုသည်မှာ အဘယ်နည်း။", "answer": s.text[:800]})
            if len(pairs) >= 5:
                break
    return [{"id": s.id, "url": s.url, **p} for p in pairs]


# --------------------------------------------------------------------------- #
# Output
# --------------------------------------------------------------------------- #
def write_outputs(sermons: list[Sermon]) -> None:
    DATASET_DIR.mkdir(parents=True, exist_ok=True)

    pd.DataFrame(
        [
            {
                "id": s.id, "title": s.title, "author": s.author, "date": s.date,
                "url": s.url, "denomination": s.denomination, "series": s.series,
                "language": s.language, "book": s.book, "chapter": s.chapter,
                "verses": s.verses, "testament": s.testament,
                "bible_references": "; ".join(s.bible_references),
                "keywords": "; ".join(s.keywords),
                "tags": "; ".join(s.tags), "chars": len(s.text),
            }
            for s in sermons
        ]
    ).to_csv(METADATA_CSV, index=False, quoting=csv.QUOTE_MINIMAL)

    def dump(name: str, rows) -> None:
        with (DATASET_DIR / name).open("w", encoding="utf-8") as f:
            for r in rows:
                f.write(json.dumps(r, ensure_ascii=False) + "\n")

    dump("train.jsonl", (
        {"id": s.id, "title": s.title, "author": s.author, "url": s.url,
         "series": s.series, "date": s.date, "language": s.language, "text": s.text}
        for s in sermons
    ))
    dump("instruction.jsonl", (
        {"instruction": "Explain this sermon", "input": s.title, "output": s.text}
        for s in sermons
    ))
    dump("qa.jsonl", (qa for s in sermons for qa in build_qa(s)))

    # Deterministic Phase-3 datasets, derived from the enriched fields.
    dump("summary.jsonl", (
        {"instruction": "Summarize this sermon", "input": s.text, "output": s.summary}
        for s in sermons if s.summary
    ))
    dump("outline.jsonl", (
        {"instruction": "Outline this sermon", "input": s.text, "output": s.outline}
        for s in sermons if s.outline
    ))
    dump("keywords.jsonl", (
        {"instruction": "List the key Burmese terms in this sermon",
         "input": s.text, "output": s.keywords}
        for s in sermons if s.keywords
    ))
    dump("verse_linking.jsonl", (
        {"id": s.id, "url": s.url, "references": s.references}
        for s in sermons if s.references
    ))


# --------------------------------------------------------------------------- #
# Main crawl loop
# --------------------------------------------------------------------------- #
def crawl(max_pages: int | None) -> None:
    RAW_DIR.mkdir(parents=True, exist_ok=True)
    state = load_state()
    visited = set(state["visited"])
    seen_hashes = set(state["seen_hashes"])
    sermons = [Sermon(**s) for s in state["sermons"]]

    queue = [u for u in SOURCES if u.rstrip("/") not in visited]
    queue += [u for u in state.get("queue", []) if u not in visited]

    processed = 0
    bar = tqdm(total=max_pages, desc="crawling", unit="page")
    while queue:
        if max_pages and processed >= max_pages:
            break
        url = queue.pop(0).rstrip("/")
        if url in visited:
            continue
        visited.add(url)

        html = fetch(url)
        processed += 1
        bar.update(1)
        if not html:
            continue

        for link in discover_links(html, url):
            if link not in visited:
                queue.append(link)

        sermon = parse_sermon(html, url)
        if sermon:
            dedupe = hashlib.sha1(sermon.text.encode("utf-8")).hexdigest()
            if dedupe not in seen_hashes:
                seen_hashes.add(dedupe)
                sermons.append(sermon)
                (RAW_DIR / f"{sermon.id}.json").write_text(
                    json.dumps(asdict(sermon), ensure_ascii=False, indent=2), encoding="utf-8"
                )

        if processed % CHECKPOINT_EVERY == 0:
            state.update(
                visited=sorted(visited), seen_hashes=sorted(seen_hashes),
                sermons=[asdict(s) for s in sermons], queue=queue,
            )
            save_state(state)
            write_outputs(sermons)

    bar.close()
    state.update(
        visited=sorted(visited), seen_hashes=sorted(seen_hashes),
        sermons=[asdict(s) for s in sermons], queue=queue,
    )
    save_state(state)
    write_outputs(sermons)
    print(f"\nDone. {len(sermons)} sermons -> {DATASET_DIR}")
    print(f"Pages visited: {len(visited)} | metadata: {METADATA_CSV}")


def main() -> int:
    ap = argparse.ArgumentParser(description="Build a Burmese sermon training dataset.")
    ap.add_argument("--max-pages", type=int, default=None, help="stop after N pages (for testing)")
    ap.add_argument("--reset", action="store_true", help="discard saved crawl state and start over")
    args = ap.parse_args()
    if args.reset and STATE_FILE.exists():
        STATE_FILE.unlink()
    crawl(args.max_pages)
    return 0


if __name__ == "__main__":
    sys.exit(main())
