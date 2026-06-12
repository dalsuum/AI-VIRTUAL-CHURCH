"""Shared helper: sing a hymn's verses through Suno customMode.

Both non-English hymn strategies (Myanmar, Tedim) compose their singing the
same way — submit the hymn's verbatim verses as customMode lyrics so the
vocals sing the real hymn, poll, download — so the machinery lives here once.
SunoStrategy supplies the gateway plumbing (headers, polling, mirror-aware
download); only the submit body differs from prompt-mode generation.
"""

from __future__ import annotations

import os

import requests

from .suno_strategy import SunoStrategy

# Suno customMode caps prompt (lyrics) length; stay safely under and cut on a
# verse boundary so the sung text never ends mid-line.
MAX_LYRICS = int(os.getenv("SUNO_CUSTOM_MAX_LYRICS", "2800"))


def trim_on_verse(lyrics: str, limit: int = MAX_LYRICS) -> str:
    if len(lyrics) <= limit:
        return lyrics
    cut = lyrics[:limit]
    for sep in ("\n\n", "\n"):
        idx = cut.rfind(sep)
        if idx > limit // 2:
            return cut[:idx].rstrip()
    return cut.rstrip()


def sing(*, title: str, lyrics: str, style: str) -> bytes:
    """Submit verses to Suno customMode and return the finished MP3 bytes.

    Raises on any provider failure — callers degrade the same way every music
    strategy does (generate_music skips the segment rather than crashing).
    """
    suno = SunoStrategy()  # raises KeyError early if SUNO_API_KEY is missing
    resp = requests.post(
        f"{suno.BASE_URL}/generate",
        headers=suno._headers,
        json={
            "prompt": trim_on_verse(lyrics),  # customMode: prompt == the exact lyrics
            "style": style,
            "title": title[:80],
            "customMode": True,
            "instrumental": False,
            "model": suno.MODEL,
            "callBackUrl": suno.CALLBACK_URL,
        },
        timeout=30,
    )
    resp.raise_for_status()
    body = resp.json()
    if body.get("code") != 200:
        raise RuntimeError(f"Suno submit rejected: {body.get('msg')!r} ({body.get('code')})")
    track = suno._poll(body["data"]["taskId"])
    return suno._download(track)
