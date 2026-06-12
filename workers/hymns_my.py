"""Burmese hymn library (dalsuum/myanmar-hymns) — load + mood selection.

The Burmese counterpart of `hymns.py`. 852 songs (410 classic hymnal + 442 modern
worship, Burmese lyrics only, chord lines stripped) live in data/hymns_my.json,
produced once by tools/import_myanmar_hymns.py. Each entry carries English mood
tags ("grateful", "anxious", …) matched against the worshipper's mood/prompt the
same way `hymns.select` does, plus "default" so selection can never fail.

Unlike the English library there are no pre-rendered recordings; the audio for a
selected hymn is composed at service time (see strategies/hymn_my_strategy.py),
while these lyrics ride along for on-screen display either way.
"""

from __future__ import annotations

import functools
import json
import os
import random

_DATA = os.path.join(os.path.dirname(os.path.abspath(__file__)), "data", "hymns_my.json")


@functools.lru_cache(maxsize=1)
def _library() -> list[dict]:
    with open(_DATA, encoding="utf-8") as fh:
        return json.load(fh)["hymns"]


def all_hymns() -> list[dict]:
    return _library()


def select(*, mood: str, prompt: str = "", query: str = "", prefer_source: str | None = "hymnal") -> dict:
    """Pick a mood-appropriate Burmese hymn.

    Match the lowercase mood/prompt words against each hymn's English mood tags;
    fall back to the "default"-tagged pool (every hymn) when nothing matches, so
    this never raises. `prefer_source` biases the pick toward the classic hymnal
    ("hymnal") over modern songs when both match — closing hymns should feel like
    a hymnal, not a praise set; pass None to weight them equally.
    """
    hay = f"{mood} {prompt} {query}".lower()
    matched = [h for h in _library() if any(tag != "default" and tag in hay for tag in h["moods"])]
    pool = matched or _library()
    if prefer_source:
        preferred = [h for h in pool if h["source"] == prefer_source]
        if preferred:
            pool = preferred
    return random.choice(pool)
