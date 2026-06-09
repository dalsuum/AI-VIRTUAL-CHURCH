"""Talking-head avatar rendering via HeyGen.

A segment's text (sermon, prayer, …) is handed to HeyGen, which renders a presenter
speaking it. We poll until the render completes, download the mp4, push it to object
storage, and hand back a playable URL so the segment can be shown as video.

Entirely optional and key-gated: with no HEYGEN_API_KEY the pipeline never calls in
here, and the worshipper still gets every segment as text. Configure with:

    HEYGEN_API_KEY     — required to enable rendering at all
    HEYGEN_AVATAR_ID   — the presenter avatar to use (required when enabled)
    HEYGEN_VOICE_ID    — the voice to speak with (required when enabled)
    HEYGEN_API_BASE    — override the API host (default https://api.heygen.com)
    HEYGEN_POLL_SECONDS / HEYGEN_MAX_WAIT — polling cadence / ceiling (defaults 6 / 300)
"""

from __future__ import annotations

import os
import time

import requests

import storage

_API_BASE = os.getenv("HEYGEN_API_BASE", "https://api.heygen.com").rstrip("/")
# HeyGen caps a single voice input; longer scripts are trimmed at a sentence boundary
# rather than rejected outright (the full words still arrive as the text asset).
_MAX_SCRIPT_CHARS = 1500


def is_enabled() -> bool:
    """True only when every credential needed for a render is present."""
    return all(os.getenv(k) for k in ("HEYGEN_API_KEY", "HEYGEN_AVATAR_ID", "HEYGEN_VOICE_ID"))


def _headers() -> dict:
    return {"X-Api-Key": os.environ["HEYGEN_API_KEY"], "Content-Type": "application/json"}


def _trim(script: str) -> str:
    if len(script) <= _MAX_SCRIPT_CHARS:
        return script
    cut = script[:_MAX_SCRIPT_CHARS]
    stop = max(cut.rfind(". "), cut.rfind("! "), cut.rfind("? "))
    return (cut[: stop + 1] if stop > 0 else cut).strip()


def _submit(script: str) -> str:
    """Kick off a render; return HeyGen's video_id."""
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
                    "input_text": _trim(script),
                    "voice_id": os.environ["HEYGEN_VOICE_ID"],
                },
            }
        ],
        "dimension": {"width": 1280, "height": 720},
    }
    resp = requests.post(f"{_API_BASE}/v2/video/generate", json=body, headers=_headers(), timeout=30)
    resp.raise_for_status()
    video_id = resp.json().get("data", {}).get("video_id")
    if not video_id:
        raise RuntimeError(f"HeyGen returned no video_id: {resp.text[:200]}")
    return video_id


def _await_render(video_id: str) -> str:
    """Poll until the render finishes; return the temporary HeyGen download URL."""
    interval = int(os.getenv("HEYGEN_POLL_SECONDS", "6"))
    deadline = time.monotonic() + int(os.getenv("HEYGEN_MAX_WAIT", "300"))
    while time.monotonic() < deadline:
        resp = requests.get(
            f"{_API_BASE}/v1/video_status.get",
            params={"video_id": video_id},
            headers=_headers(),
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


def render(session_token: str, segment: str, script: str) -> str:
    """Render `script` as a talking-head video, store it, return a playable URL.

    Raises on any failure — the caller logs and degrades to the text-only segment."""
    video_id = _submit(script)
    download_url = _await_render(video_id)

    mp4 = requests.get(download_url, timeout=120)
    mp4.raise_for_status()

    key = f"avatars/{session_token}/{segment}.mp4"
    storage.upload_bytes(key, mp4.content, "video/mp4")
    # Consistent with how stored audio is surfaced: storage_key carries a presigned,
    # directly-playable URL for the client (see ServiceController::show).
    return storage.presign(key, expires=6 * 3600)
