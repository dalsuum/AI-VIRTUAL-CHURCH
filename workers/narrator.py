"""Narration (text-to-speech) for the spoken segments.

Each spoken segment's words — opening prayer, scripture, message, benediction — are
sent to a text-to-speech endpoint, the returned audio is stored, and a playable URL
is handed back so the segment can be *heard* as well as read.

Entirely optional and key-gated: with no TTS_API_KEY the pipeline never calls in
here, and the worshipper still gets every segment as text (and, if HeyGen is on, as
a talking-head video). Talks to any OpenAI-compatible /audio/speech endpoint
(OpenAI itself, or a compatible gateway). Configure with:

    TTS_API_KEY    — required to enable narration at all
    TTS_BASE_URL   — API host (default https://api.openai.com/v1)
    TTS_MODEL      — speech model (default gpt-4o-mini-tts)
    TTS_VOICE      — the voice to read with (default 'onyx', a warm narrator)
    TTS_FORMAT     — audio container (default mp3)
"""

from __future__ import annotations

import os
import re

import requests

import storage

_BASE = os.getenv("TTS_BASE_URL", "https://api.openai.com/v1").rstrip("/")
# TTS endpoints cap a single request near 4096 characters; longer scripts (the
# message especially) are split on sentence boundaries and the audio is stitched
# back together rather than truncated.
_MAX_CHARS = int(os.getenv("TTS_MAX_CHARS", "3500"))


def is_enabled() -> bool:
    """True only when a TTS key is configured."""
    return bool(os.getenv("TTS_API_KEY"))


def _clean(text: str) -> str:
    """Strip delivery cues the model leaves in the script (e.g. the message's
    [pause] markers) so the narrator reads the words, not the stage directions."""
    text = re.sub(r"\[[^\]]*\]", " ", text)  # [pause] and any other [cue]
    return re.sub(r"\s+", " ", text).strip()


def _chunks(text: str) -> list[str]:
    """Split `text` into <= _MAX_CHARS pieces, preferring sentence boundaries."""
    if len(text) <= _MAX_CHARS:
        return [text]
    parts: list[str] = []
    buf = ""
    for sentence in re.split(r"(?<=[.!?])\s+", text):
        if buf and len(buf) + len(sentence) + 1 > _MAX_CHARS:
            parts.append(buf)
            buf = ""
        # A single sentence longer than the cap is hard-split as a last resort.
        while len(sentence) > _MAX_CHARS:
            parts.append(sentence[:_MAX_CHARS])
            sentence = sentence[_MAX_CHARS:]
        buf = f"{buf} {sentence}".strip()
    if buf:
        parts.append(buf)
    return parts


def _speak(text: str) -> bytes:
    """Synthesize a single (already size-bounded) chunk; return the audio bytes."""
    resp = requests.post(
        f"{_BASE}/audio/speech",
        headers={
            "Authorization": f"Bearer {os.environ['TTS_API_KEY']}",
            "Content-Type": "application/json",
        },
        json={
            "model": os.getenv("TTS_MODEL", "gpt-4o-mini-tts"),
            "voice": os.getenv("TTS_VOICE", "onyx"),
            "input": text,
            "response_format": os.getenv("TTS_FORMAT", "mp3"),
        },
        timeout=120,
    )
    resp.raise_for_status()
    return resp.content


def narrate(session_token: str, segment: str, text: str) -> str:
    """Read `text` aloud, store the audio, and return a playable URL.

    Raises on any failure — the caller logs and the segment stays text-only."""
    clean = _clean(text)
    audio = b"".join(_speak(chunk) for chunk in _chunks(clean) if chunk)

    fmt = os.getenv("TTS_FORMAT", "mp3")
    content_type = "audio/mpeg" if fmt == "mp3" else f"audio/{fmt}"
    key = f"narration/{session_token}/{segment}.{fmt}"
    storage.upload_bytes(key, audio, content_type)
    # Consistent with avatar.render(): hand back a presigned, directly-playable URL.
    return storage.presign(key, expires=6 * 3600)
