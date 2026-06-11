"""Narration (text-to-speech) for the spoken segments.

Each spoken segment's words — opening prayer, scripture, message, benediction — are
sent to a text-to-speech endpoint, the returned audio is stored, and a playable URL
is handed back so the segment can be *heard* as well as read.

Providers (set via Admin Console → Settings → Narration voice):

  'edge_tts' — Microsoft Edge TTS: free, no API key, high-quality neural voices.
    EDGE_TTS_VOICE — voice name (default 'en-US-AriaNeural', a warm female narrator)
    EDGE_TTS_RATE  — speaking rate adjustment, e.g. '-5%' to slow down (default '')

  'openai' — OpenAI's own (or any compatible gateway) text-to-speech:
    TTS_API_KEY    — required to enable this provider at all
    TTS_BASE_URL   — API host (default https://api.openai.com/v1)
    TTS_MODEL      — speech model (default gpt-4o-mini-tts)
    TTS_VOICE      — the voice to read with (default 'onyx', a warm narrator)
    TTS_FORMAT     — audio container (default mp3)

  'kokoro' — the open hexgrad/kokoro-82m model served through OpenRouter (or any
  compatible gateway). Falls back to the OPENROUTER_* credentials the LLM already
  uses, so it works out of the box once those are set:
    KOKORO_API_KEY  — provider key (default: OPENROUTER_API_KEY)
    KOKORO_BASE_URL — API host (default: OPENROUTER_BASE_URL or OpenRouter's)
    KOKORO_MODEL    — speech model (default hexgrad/kokoro-82m)
    KOKORO_VOICE    — the voice to read with (default 'af_heart')
    KOKORO_FORMAT   — audio container (default: TTS_FORMAT or mp3)
"""

from __future__ import annotations

import asyncio
import os
import re

import requests

import storage

# TTS endpoints cap a single request near 4096 characters; longer scripts (the
# message especially) are split on sentence boundaries and the audio is stitched
# back together rather than truncated.
_MAX_CHARS = int(os.getenv("TTS_MAX_CHARS", "3500"))


def _providers() -> dict[str, dict]:
    """The API-based voice providers keyed by narration_mode."""
    return {
        "openai": {
            "api_key": os.getenv("TTS_API_KEY"),
            "base_url": os.getenv("TTS_BASE_URL", "https://api.openai.com/v1").rstrip("/"),
            "model": os.getenv("TTS_MODEL", "gpt-4o-mini-tts"),
            "voice": os.getenv("TTS_VOICE", "onyx"),
            "fmt": os.getenv("TTS_FORMAT", "mp3"),
        },
        "kokoro": {
            "api_key": os.getenv("KOKORO_API_KEY") or os.getenv("OPENROUTER_API_KEY"),
            "base_url": (os.getenv("KOKORO_BASE_URL")
                         or os.getenv("OPENROUTER_BASE_URL", "https://openrouter.ai/api/v1")).rstrip("/"),
            "model": os.getenv("KOKORO_MODEL", "hexgrad/kokoro-82m"),
            "voice": os.getenv("KOKORO_VOICE", "af_heart"),
            "fmt": os.getenv("KOKORO_FORMAT") or os.getenv("TTS_FORMAT", "mp3"),
        },
    }


def is_enabled(mode: str = "openai") -> bool:
    """True when the chosen provider is ready to use.
    edge_tts needs no key so it is always enabled."""
    if mode == "edge_tts":
        return True
    cfg = _providers().get(mode)
    return bool(cfg and cfg["api_key"])


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


def _speak(text: str, cfg: dict) -> bytes:
    """Synthesize a single (already size-bounded) chunk via an OpenAI-compatible
    /audio/speech endpoint; return the audio bytes."""
    resp = requests.post(
        f"{cfg['base_url']}/audio/speech",
        headers={
            "Authorization": f"Bearer {cfg['api_key']}",
            "Content-Type": "application/json",
        },
        json={
            "model": cfg["model"],
            "voice": cfg["voice"],
            "input": text,
            "response_format": cfg["fmt"],
        },
        timeout=60,
    )
    resp.raise_for_status()
    return resp.content


async def _speak_edge(text: str, voice: str) -> bytes:
    """Synthesize `text` with Microsoft Edge TTS; return mp3 bytes."""
    import edge_tts  # imported lazily so missing package only errors for this mode
    rate = os.getenv("EDGE_TTS_RATE", "")
    kwargs = {"rate": rate} if rate else {}
    communicate = edge_tts.Communicate(text, voice, **kwargs)
    audio = b""
    async for chunk in communicate.stream():
        if chunk["type"] == "audio":
            audio += chunk["data"]
    return audio


def _narrate_edge(text: str, voice: str) -> bytes:
    """Run the async Edge TTS synthesis from a sync context."""
    parts = _chunks(text)
    return b"".join(asyncio.run(_speak_edge(chunk, voice)) for chunk in parts if chunk)


def narrate(session_token: str, segment: str, text: str, mode: str = "openai", voice: str = "") -> str:
    """Read `text` aloud with the `mode` provider, store the audio, and return a
    playable URL.

    Raises on any failure — the caller logs and the segment stays text-only."""
    clean = _clean(text)

    if mode == "edge_tts":
        resolved_voice = voice or os.getenv("EDGE_TTS_VOICE", "en-US-AriaNeural")
        audio = _narrate_edge(clean, resolved_voice)
        fmt = "mp3"
    else:
        cfg = _providers().get(mode)
        if not cfg or not cfg["api_key"]:
            raise RuntimeError(f"narration provider {mode!r} is not configured")
        audio = b"".join(_speak(chunk, cfg) for chunk in _chunks(clean) if chunk)
        fmt = cfg["fmt"]

    content_type = "audio/mpeg" if fmt == "mp3" else f"audio/{fmt}"
    key = f"narration/{session_token}/{segment}.{fmt}"
    storage.upload_bytes(key, audio, content_type)
    # Consistent with avatar.render(): hand back a presigned, directly-playable URL.
    return storage.presign(key, expires=6 * 3600)
