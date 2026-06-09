"""Suno strategy — generate original worship music from a text prompt.

Backed by the KIE AI gateway (https://docs.kie.ai/suno-api), which wraps Suno
behind a real REST API. There is no official public Suno API; KIE is the provider
the SUNO_API_KEY belongs to. Override SUNO_API_URL/SUNO_MODEL to retarget.
"""

from __future__ import annotations

import os
import time

import requests

from . import MusicResult, MusicStrategy


class SunoStrategy(MusicStrategy):
    BASE_URL = os.getenv("SUNO_API_URL", "https://api.kie.ai/api/v1")
    MODEL = os.getenv("SUNO_MODEL", "V4_5")
    # KIE requires a callBackUrl by schema even though we poll instead of receiving
    # callbacks; any valid URL satisfies it. Point it somewhere harmless.
    CALLBACK_URL = os.getenv("SUNO_CALLBACK_URL", "https://example.com/suno-callback")
    POLL_INTERVAL = 5
    POLL_TIMEOUT = 240
    # Non-custom mode caps the prompt at ~500 chars; stay safely under.
    MAX_PROMPT = 480

    # KIE statuses that mean "give up on this job".
    FAIL_STATUSES = {
        "CREATE_TASK_FAILED",
        "GENERATE_AUDIO_FAILED",
        "CALLBACK_EXCEPTION",
        "SENSITIVE_WORD_ERROR",
    }

    def __init__(self) -> None:
        self.api_key = os.environ["SUNO_API_KEY"]

    @property
    def _headers(self) -> dict:
        return {"Authorization": f"Bearer {self.api_key}", "Content-Type": "application/json"}

    def fetch(self, *, mood: str, prompt: str, query: str) -> MusicResult:
        task_id = self._submit((prompt or f"A worship song that feels {mood}")[: self.MAX_PROMPT])
        audio_url = self._poll(task_id)

        # KIE serves the finished track from its own CDN (tempfile.aiquickdraw.com),
        # which drops datacenter egress — the worker can't re-download it, but the
        # worshipper's browser can. So hand back the CDN URL directly as the playable
        # URL (same "storage_key carries a directly-playable URL" convention as
        # avatar.render()/narrator.narrate()) rather than restoring it locally.
        return MusicResult(
            asset_type="audio",
            storage_key=audio_url,
            provider_ref=task_id,
            title=f"Worship ({mood})",
        )

    def _submit(self, prompt: str) -> str:
        resp = requests.post(
            f"{self.BASE_URL}/generate",
            headers=self._headers,
            json={
                "prompt": prompt,
                "customMode": False,   # simple prompt mode: only prompt/model needed
                "instrumental": False, # worship song with vocals, not a backing track
                "model": self.MODEL,
                "callBackUrl": self.CALLBACK_URL,
            },
            timeout=30,
        )
        resp.raise_for_status()
        body = resp.json()
        if body.get("code") != 200:
            raise RuntimeError(f"Suno submit rejected: {body.get('msg')!r} ({body.get('code')})")
        return body["data"]["taskId"]

    def _poll(self, task_id: str) -> str:
        deadline = time.time() + self.POLL_TIMEOUT
        while time.time() < deadline:
            resp = requests.get(
                f"{self.BASE_URL}/generate/record-info",
                headers=self._headers,
                params={"taskId": task_id},
                timeout=30,
            )
            resp.raise_for_status()
            data = resp.json().get("data") or {}
            status = data.get("status")
            if status == "SUCCESS":
                tracks = (data.get("response") or {}).get("sunoData") or []
                for track in tracks:
                    if track.get("audioUrl"):
                        return track["audioUrl"]
                raise RuntimeError(f"Suno job {task_id} succeeded but returned no audioUrl")
            if status in self.FAIL_STATUSES:
                raise RuntimeError(f"Suno generation failed for {task_id}: {status}")
            time.sleep(self.POLL_INTERVAL)
        raise TimeoutError(f"Suno generation timed out for task {task_id}")
