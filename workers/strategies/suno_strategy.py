"""Suno strategy — generate original worship music from a text prompt."""

from __future__ import annotations

import os
import time

import requests

from . import MusicResult, MusicStrategy
from storage import upload_bytes  # noqa: E402  (workers/storage.py)


class SunoStrategy(MusicStrategy):
    BASE_URL = os.getenv("SUNO_API_URL", "https://api.suno.ai/v1")
    POLL_INTERVAL = 5
    POLL_TIMEOUT = 180

    def __init__(self) -> None:
        self.api_key = os.environ["SUNO_API_KEY"]

    def fetch(self, *, mood: str, prompt: str, query: str) -> MusicResult:
        job_id = self._submit(prompt or f"A worship song that feels {mood}")
        audio_url = self._poll(job_id)

        audio_bytes = requests.get(audio_url, timeout=60).content
        key = f"music/suno/{job_id}.mp3"
        upload_bytes(key, audio_bytes, content_type="audio/mpeg")

        return MusicResult(
            asset_type="audio",
            storage_key=key,
            provider_ref=job_id,
            title=f"Worship ({mood})",
        )

    def _submit(self, prompt: str) -> str:
        resp = requests.post(
            f"{self.BASE_URL}/generate",
            headers={"Authorization": f"Bearer {self.api_key}"},
            json={"prompt": prompt, "make_instrumental": False},
            timeout=30,
        )
        resp.raise_for_status()
        return resp.json()["id"]

    def _poll(self, job_id: str) -> str:
        deadline = time.time() + self.POLL_TIMEOUT
        while time.time() < deadline:
            resp = requests.get(
                f"{self.BASE_URL}/clips/{job_id}",
                headers={"Authorization": f"Bearer {self.api_key}"},
                timeout=30,
            )
            resp.raise_for_status()
            data = resp.json()
            if data.get("status") == "complete" and data.get("audio_url"):
                return data["audio_url"]
            time.sleep(self.POLL_INTERVAL)
        raise TimeoutError(f"Suno generation timed out for job {job_id}")
