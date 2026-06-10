"""Hymn strategy — serve a pre-seeded public-domain hymn. No AI, no credit.

Two presentations, sharing one mood->hymn matcher (hymns.select):

  * sung=True  (the `hymn_sung` source, default) — a real SUNG recording from the
                public-domain library (Internet Archive 78rpm). Only the ~10 hymns
                with a recording are eligible, so the worshipper always hears voices.
  * sung=False (the `hymn` source) — the hymn rendered MIDI->MP3 (instrumental),
                with the lyrics shown on screen. All seeded hymns are eligible.

Either way the lyrics (Open Hymnal, public domain) ride along for the player to
display. Everything was produced ahead of time by seed_hymns.py, so there's no
provider call and nothing to pay for at service time.
"""

from __future__ import annotations

import storage
from hymns import HYMNS, select

from . import MusicResult, MusicStrategy

SUNG_KEY = "hymns/{slug}.sung.mp3"
INSTRUMENTAL_KEY = "hymns/{slug}.mp3"
LYRICS_KEY = "hymns/{slug}.txt"


class HymnStrategy(MusicStrategy):
    def __init__(self, *, sung: bool = True) -> None:
        self.sung = sung

    def fetch(self, *, mood: str, prompt: str, query: str) -> MusicResult:
        eligible, sung = self._eligible()
        if not eligible:
            raise RuntimeError(
                "No hymns are seeded — run `python workers/seed_hymns.py` to populate "
                "the public-domain hymn library before using a hymn music source."
            )

        hymn = select(mood=mood, prompt=prompt, query=query, eligible=eligible)
        slug = hymn["slug"]
        if sung:
            key = SUNG_KEY.format(slug=slug)
            rec = hymn["recording"]
            title = f"{hymn['title']} · {rec['performer']} ({rec['year']})"
        else:
            key = INSTRUMENTAL_KEY.format(slug=slug)
            title = f"{hymn['title']} · {hymn['author']}"

        # Return the RAW object key (like SunoStrategy); generate_music presigns it.
        # The hymn library is fixed, so it's never added to the reusable mood pool.
        return MusicResult(
            asset_type="audio",
            storage_key=key,
            provider_ref=slug,
            title=title,
            lyrics=storage.read_text(LYRICS_KEY.format(slug=slug)),
        )

    def _eligible(self) -> tuple[set[str], bool]:
        """Slugs we can actually serve, and whether they're the sung set.

        Prefer sung recordings when this is the sung source; if none were seeded
        (e.g. the Archive was unreachable at seed time), degrade to the instrumental
        renders so the worship segment still has music rather than nothing.
        """
        if self.sung:
            sung = {h["slug"] for h in HYMNS
                    if h.get("recording") and storage.exists(SUNG_KEY.format(slug=h["slug"]))}
            if sung:
                return sung, True
        instrumental = {h["slug"] for h in HYMNS
                        if storage.exists(INSTRUMENTAL_KEY.format(slug=h["slug"]))}
        return instrumental, False
