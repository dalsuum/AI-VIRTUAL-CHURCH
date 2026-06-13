"""
Celery tasks — Burmese (Myanmar) localization stage in the worship pipeline
===========================================================================
Pipeline order (chain):
  build_liturgy -> generate_sermon_my -> localize_burmese -> narrate_tts

Key design rule:
  SCRIPTURE never goes through the LLM. Pull verses directly from the
  vendored Myanmar Bible corpus (workers/data/judson1835.json via bible_api),
  exactly as the existing English/Tedim Bible lookup does. The LLM handles
  only PROSE: sermon body, prayers, announcements, transitions.

Usage from generate_text_segments when language == 'my':
    localize_segment_burmese.delay(segment_dict)

The segment dict matches what _post_asset sends, augmented with 'ref' for
scripture lookups:
    {"id": int, "type": "scripture"|"sermon"|"prayer"|"benediction",
     "english_text": str, "ref": str|None}
"""

from __future__ import annotations

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import requests
from celery import shared_task

import bible_api  # workers/bible_api.py

BURMESE_LLM_URL = os.getenv("BURMESE_LLM_URL", "http://127.0.0.1:8002")


def _translate(text: str) -> str:
    """HTTP call to the local FastAPI Burmese service. Redis-cached there (30 days)."""
    r = requests.post(
        f"{BURMESE_LLM_URL}/burmese/translate",
        json={"text": text, "direction": "en2my"},
        timeout=600,
    )
    r.raise_for_status()
    return r.json()["text"]


def _verse(ref: str) -> str:
    """Exact Myanmar Bible verse from local Judson 1835 corpus via FastAPI /burmese/verse."""
    r = requests.get(
        f"{BURMESE_LLM_URL}/burmese/verse",
        params={"ref": ref, "lang": "my"},
        timeout=30,
    )
    r.raise_for_status()
    return r.json()["text"]


@shared_task(
    bind=True,
    max_retries=2,
    default_retry_delay=30,
    acks_late=True,
    name="tasks.localize_segment_burmese",
)
def localize_segment_burmese(self, segment: dict) -> dict:
    """
    Fills segment["burmese_text"] in-place and returns the enriched dict.

    Scripture uses the exact Myanmar Bible corpus (Judson 1835) — no LLM, no
    inference cost. All prose segments are translated paragraph-by-paragraph
    (1-3 sentence chunks) because the model is more reliable on short inputs.
    """
    try:
        if segment["type"] == "scripture" and segment.get("ref"):
            # Exact corpus lookup first; fall back to LLM translation on miss.
            try:
                segment["burmese_text"] = _verse(segment["ref"])
            except requests.HTTPError as exc:
                if exc.response is not None and exc.response.status_code == 404:
                    segment["burmese_text"] = _translate(segment["english_text"])
                else:
                    raise
        else:
            # Paragraph-by-paragraph: more reliable than full sermons in one shot.
            paras = [p for p in segment["english_text"].split("\n\n") if p.strip()]
            segment["burmese_text"] = "\n\n".join(_translate(p) for p in paras)

        return segment

    except requests.RequestException as exc:
        raise self.retry(exc=exc)


@shared_task(name="tasks.narrate_burmese")
def narrate_burmese(session_token: str, segment_name: str, burmese_text: str) -> str:
    """TTS for Burmese text using the native local MMS-TTS Burmese checkpoint."""
    import narrator

    return narrator.narrate(
        session_token,
        segment_name,
        burmese_text,
        mode="mms_tts",
        language="my",
    )
