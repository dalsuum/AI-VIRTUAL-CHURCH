"""Myanmar hymn strategy — a Burmese hymn, sung, with the words on screen.

The Burmese library (dalsuum/myanmar-hymns) is lyrics-only: there are no
public-domain recordings or MIDI renders to pre-seed the way seed_hymns.py does
for the English hymnal. So this strategy composes the singing at service time:

  1. hymns_my.select() picks a mood-appropriate hymn (852 songs, never fails);
  2. the hymn's ACTUAL Burmese verses are submitted to Suno (KIE gateway) in
     customMode, so the generated vocals sing the real hymn text — not an AI
     paraphrase — in a traditional congregational style;
  3. the verses also ride along in `lyrics` for on-screen display, exactly like
     the English hymn sources, so the worshipper can sing along.

Generated tracks are cached under hymns_my/<slug>.mp3 in the same storage backend
as everything else: the first worshipper to receive a given hymn pays the Suno
credit, every later service that selects it plays the stored file free — the
Burmese analog of the pre-seeded English library, just filled lazily.

Requires SUNO_API_KEY (same credential as SunoStrategy). If Suno fails or no key
is set and no cached render exists, fetch() raises and generate_music skips the
music segment gracefully, matching every other strategy's degradation path.
"""

from __future__ import annotations

import os

import storage
from hymns_my import select

from . import MusicResult, MusicStrategy
from ._suno_custom import sing

CACHE_KEY = "hymns_my/{slug}.mp3"

_STYLE = os.getenv(
    "SUNO_MY_STYLE",
    "Traditional Christian hymn, reverent congregational choir with piano and organ, "
    "sung in the Burmese (Myanmar) language",
)



class MyanmarHymnStrategy(MusicStrategy):
    """Mood-matched Burmese hymn: cached render if we have one, else sing it via Suno."""

    def fetch(self, *, mood: str, prompt: str, query: str) -> MusicResult:
        hymn = select(mood=mood, prompt=prompt, query=query)
        slug, title, lyrics = hymn["slug"], hymn["title"], hymn["lyrics"]
        key = CACHE_KEY.format(slug=slug)

        if not storage.exists(key):
            audio = sing(title=title, lyrics=lyrics, style=_STYLE)
            storage.upload_bytes(key, audio, "audio/mpeg")

        # RAW object key, like the other audio strategies; generate_music presigns it.
        # Never pooled by mood (the cache above already reuses by hymn, which keeps
        # variety — pooling by mood would pin one hymn per mood).
        return MusicResult(
            asset_type="audio",
            storage_key=key,
            provider_ref=slug,
            title=title,
            lyrics=lyrics,
        )

