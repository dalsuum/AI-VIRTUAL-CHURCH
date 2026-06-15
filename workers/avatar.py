"""Talking-head avatar rendering — HeyGen or D-ID backend.

Set AVATAR_PROVIDER to 'heygen' (default) or 'did' to choose the backend.

HeyGen config:
    HEYGEN_API_KEY     — required
    HEYGEN_AVATAR_ID   — the presenter avatar to use
    HEYGEN_VOICE_ID    — the voice to speak with
    HEYGEN_API_BASE    — override API host (default https://api.heygen.com)
    HEYGEN_POLL_SECONDS / HEYGEN_MAX_WAIT — polling cadence / ceiling (defaults 6 / 300)

D-ID config:
    DID_API_KEY        — required (Basic-auth key from D-ID dashboard)
    DID_SOURCE_URL     — public image URL of the presenter (required)
    DID_VOICE_ID       — voice id from your TTS provider (required)
    DID_VOICE_PROVIDER — tts provider: 'microsoft' | 'amazon' | 'elevenlabs' (default 'microsoft')
    DID_API_BASE       — override API host (default https://api.d-id.com)
    DID_POLL_SECONDS / DID_MAX_WAIT — polling cadence / ceiling (defaults 6 / 300)

Entirely optional and key-gated: with no credentials the pipeline never calls in
here, and the worshipper still gets every segment as text.
"""

from __future__ import annotations

import os
import time

import requests

import storage

# HeyGen caps a single voice input at ~1500 chars. D-ID supports much longer
# scripts — use provider-specific limits, tunable via env vars.
_HEYGEN_MAX_CHARS = int(os.getenv("HEYGEN_MAX_SCRIPT_CHARS", "1500"))
_DID_MAX_CHARS    = int(os.getenv("DID_MAX_SCRIPT_CHARS", "5000"))


def _provider() -> str:
    return os.getenv("AVATAR_PROVIDER", "heygen").lower()


def _max_chars() -> int:
    return _DID_MAX_CHARS if _provider() == "did" else _HEYGEN_MAX_CHARS


def is_enabled() -> bool:
    """True only when every credential needed for the configured provider is present."""
    if _provider() == "did":
        # At minimum the API key must be set; source URL and voice fall back to
        # gendered vars — enough to serve at least one gender.
        return bool(os.getenv("DID_API_KEY") and (
            os.getenv("DID_SOURCE_URL_MALE") or os.getenv("DID_SOURCE_URL_FEMALE") or os.getenv("DID_SOURCE_URL")
        ) and (
            os.getenv("DID_VOICE_ID_MALE") or os.getenv("DID_VOICE_ID_FEMALE") or os.getenv("DID_VOICE_ID")
        ))
    return all(os.getenv(k) for k in ("HEYGEN_API_KEY", "HEYGEN_AVATAR_ID", "HEYGEN_VOICE_ID"))


def _chunks(script: str) -> list[str]:
    """Split `script` into provider-sized pieces at sentence boundaries.
    Returns a single-element list for scripts that fit within the limit."""
    limit = _max_chars()
    if len(script) <= limit:
        return [script]
    parts, buf = [], ""
    for sentence in __import__("re").split(r"(?<=[.!?])\s+", script):
        if buf and len(buf) + len(sentence) + 1 > limit:
            parts.append(buf.strip())
            buf = ""
        while len(sentence) > limit:
            if buf:
                parts.append(buf.strip())
                buf = ""
            parts.append(sentence[:limit].strip())
            sentence = sentence[limit:]
        buf = f"{buf} {sentence}".strip() if buf else sentence
    if buf:
        parts.append(buf.strip())
    return parts or [script[:limit]]


# ---------------------------------------------------------------------------
# HeyGen backend
# ---------------------------------------------------------------------------

def _heygen_headers() -> dict:
    return {"X-Api-Key": os.environ["HEYGEN_API_KEY"], "Content-Type": "application/json"}


def _heygen_submit(script: str) -> str:
    base = os.getenv("HEYGEN_API_BASE", "https://api.heygen.com").rstrip("/")
    body = {
        "video_inputs": [
            {
                "character": {
                    "type": "avatar",
                    "avatar_id": os.environ["HEYGEN_AVATAR_ID"],
                    "avatar_style": "normal",
                },
                "voice": {
                    "type": "text",
                    "input_text": _chunks(script)[0],  # HeyGen: single chunk only
                    "voice_id": os.environ["HEYGEN_VOICE_ID"],
                },
            }
        ],
        "dimension": {"width": 1280, "height": 720},
    }
    resp = requests.post(f"{base}/v2/video/generate", json=body, headers=_heygen_headers(), timeout=30)
    resp.raise_for_status()
    video_id = resp.json().get("data", {}).get("video_id")
    if not video_id:
        raise RuntimeError(f"HeyGen returned no video_id: {resp.text[:200]}")
    return video_id


def _heygen_await(video_id: str) -> str:
    base = os.getenv("HEYGEN_API_BASE", "https://api.heygen.com").rstrip("/")
    interval = int(os.getenv("HEYGEN_POLL_SECONDS", "6"))
    deadline = time.monotonic() + int(os.getenv("HEYGEN_MAX_WAIT", "300"))
    while time.monotonic() < deadline:
        resp = requests.get(
            f"{base}/v1/video_status.get",
            params={"video_id": video_id},
            headers=_heygen_headers(),
            timeout=30,
        )
        resp.raise_for_status()
        data = resp.json().get("data", {})
        status = data.get("status")
        if status == "completed":
            url = data.get("video_url")
            if not url:
                raise RuntimeError("HeyGen reported completed but gave no video_url")
            return url
        if status == "failed":
            raise RuntimeError(f"HeyGen render failed: {data.get('error') or 'unknown error'}")
        time.sleep(interval)
    raise TimeoutError(f"HeyGen render {video_id} did not complete within the time budget")


# ---------------------------------------------------------------------------
# D-ID backend
# ---------------------------------------------------------------------------

def _did_headers() -> dict:
    import base64
    token = base64.b64encode(os.environ["DID_API_KEY"].encode()).decode()
    return {"Authorization": f"Basic {token}", "Content-Type": "application/json"}


def _did_submit(chunk: str, gender: str = "female") -> str:
    base = os.getenv("DID_API_BASE", "https://api.d-id.com").rstrip("/")
    suffix = gender.upper()
    source_url = (os.getenv(f"DID_SOURCE_URL_{suffix}")
                  or os.getenv("DID_SOURCE_URL")
                  or os.environ[f"DID_SOURCE_URL_{suffix}"])
    voice_id = (os.getenv(f"DID_VOICE_ID_{suffix}")
                or os.getenv("DID_VOICE_ID")
                or os.environ[f"DID_VOICE_ID_{suffix}"])
    body = {
        "source_url": source_url,
        "script": {
            "type": "text",
            "input": chunk,
            "provider": {
                "type": os.getenv("DID_VOICE_PROVIDER", "microsoft"),
                "voice_id": voice_id,
            },
        },
    }
    resp = requests.post(f"{base}/talks", json=body, headers=_did_headers(), timeout=30)
    resp.raise_for_status()
    talk_id = resp.json().get("id")
    if not talk_id:
        raise RuntimeError(f"D-ID returned no talk id: {resp.text[:200]}")
    return talk_id


def _did_await(talk_id: str) -> str:
    base = os.getenv("DID_API_BASE", "https://api.d-id.com").rstrip("/")
    interval = int(os.getenv("DID_POLL_SECONDS", "6"))
    deadline = time.monotonic() + int(os.getenv("DID_MAX_WAIT", "300"))
    while time.monotonic() < deadline:
        resp = requests.get(f"{base}/talks/{talk_id}", headers=_did_headers(), timeout=30)
        resp.raise_for_status()
        data = resp.json()
        status = data.get("status")
        if status == "done":
            url = data.get("result_url")
            if not url:
                raise RuntimeError("D-ID reported done but gave no result_url")
            return url
        if status == "error":
            raise RuntimeError(f"D-ID render failed: {data.get('description') or 'unknown error'}")
        time.sleep(interval)
    raise TimeoutError(f"D-ID talk {talk_id} did not complete within the time budget")


# ---------------------------------------------------------------------------
# Public interface
# ---------------------------------------------------------------------------

def render(session_token: str, segment: str, script: str, gender: str = "female") -> str:
    """Render `script` as one or more talking-head videos, store them, and return a
    playable URL (single part) or JSON array of URLs (multiple parts).

    Raises on any failure — the caller logs and degrades to the text-only segment."""
    import json

    parts = _chunks(script)
    urls: list[str] = []

    for i, chunk in enumerate(parts):
        if _provider() == "did":
            talk_id = _did_submit(chunk, gender=gender)
            download_url = _did_await(talk_id)
        else:
            video_id = _heygen_submit(chunk)
            download_url = _heygen_await(video_id)

        mp4 = requests.get(download_url, timeout=120)
        mp4.raise_for_status()

        suffix = f"_{i}" if len(parts) > 1 else ""
        key = f"avatars/{session_token}/{segment}{suffix}.mp4"
        storage.upload_bytes(key, mp4.content, "video/mp4")
        urls.append(storage.presign(key, expires=6 * 3600))

    return json.dumps(urls) if len(urls) > 1 else urls[0]
