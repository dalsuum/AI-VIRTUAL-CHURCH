"""Tedim (ZBC Labu Lui) hymn library — load + mood selection.

The Tedim counterpart of `hymns.py` / `hymns_my.py`. The data file
(data/hymns_td.json) is produced on this machine by tools/seed_tedim_hymns.py,
which collects the ZBC Labu Lui hymnal from labusaal.com — run it once per
machine, exactly like seed_hymns.py seeds the English library. Each entry
carries the Tedim title, the English original it translates, the verses, an
optional YouTube id (real Tedim singing), and English mood tags derived from
the original hymn's title plus Tedim-vocabulary signals.
"""

from __future__ import annotations

import functools
import json
import os
import random

_DATA = os.path.join(os.path.dirname(os.path.abspath(__file__)), "data", "hymns_td.json")


@functools.lru_cache(maxsize=1)
def _library() -> list[dict]:
    if not os.path.exists(_DATA):
        raise RuntimeError(
            "The Tedim hymn library is not seeded — run "
            "`python workers/tools/seed_tedim_hymns.py` to collect it before "
            "using the Tedim music source."
        )
    with open(_DATA, encoding="utf-8") as fh:
        return json.load(fh)["hymns"]


def all_hymns() -> list[dict]:
    return _library()


def select(*, mood: str, prompt: str = "", query: str = "", prefer_youtube: bool = True) -> dict:
    """Pick a mood-appropriate Tedim hymn.

    Match the lowercase mood/prompt words against each hymn's mood tags; fall
    back to the whole library when nothing matches, so this never raises.
    `prefer_youtube` biases the pick toward hymns that carry a YouTube embed —
    real Tedim voices at zero AI cost — when any matching hymn has one.
    """
    hay = f"{mood} {prompt} {query}".lower()
    matched = [h for h in _library() if any(tag != "default" and tag in hay for tag in h["moods"])]
    pool = matched or _library()
    if prefer_youtube:
        with_yt = [h for h in pool if h.get("youtube_id")]
        if with_yt:
            pool = with_yt
    return random.choice(pool)
