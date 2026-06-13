"""
Celery tasks — Tedim localization stage in the worship pipeline
================================================================
Pipeline order (chain):
  build_liturgy -> generate_sermon_en -> localize_tedim -> narrate_tts

Key design rule:
  SCRIPTURE never goes through the LLM. Pull verses directly from the
  vendored Tedim Bible corpus (workers/data/tedim1932.json via bible_api),
  exactly as the existing English/Myanmar Bible lookup does. The LLM handles
  only PROSE: sermon body, prayers, announcements, transitions.

Usage from generate_text_segments when language == 'td':
    localize_segment_tedim.delay(segment_dict)

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

TEDIM_LLM_URL = os.getenv("TEDIM_LLM_URL", "http://127.0.0.1:8001")


def _translate(text: str) -> str:
    """HTTP call to the local FastAPI Tedim service. Redis-cached there (30 days)."""
    r = requests.post(
        f"{TEDIM_LLM_URL}/tedim/translate",
        json={"text": text, "direction": "en2zo"},
        timeout=600,
    )
    r.raise_for_status()
    return r.json()["text"]


def _verse(ref: str) -> str:
    """Exact Tedim Bible verse from local corpus via FastAPI /tedim/verse."""
    r = requests.get(
        f"{TEDIM_LLM_URL}/tedim/verse",
        params={"ref": ref, "lang": "td"},
        timeout=30,
    )
    r.raise_for_status()
    return r.json()["text"]


@shared_task(
    bind=True,
    max_retries=2,
    default_retry_delay=30,
    acks_late=True,
    name="tasks.localize_segment_tedim",
)
def localize_segment_tedim(self, segment: dict) -> dict:
    """
    Fills segment["tedim_text"] in-place and returns the enriched dict.

    Scripture uses the exact Tedim Bible corpus — no LLM, no inference cost.
    All prose segments are translated paragraph-by-paragraph (1-3 sentence
    chunks) because the 3B model is much more reliable on short inputs.
    """
    try:
        if segment["type"] == "scripture" and segment.get("ref"):
            # Try exact corpus lookup first; fall back to LLM translation on miss.
            try:
                segment["tedim_text"] = _verse(segment["ref"])
            except requests.HTTPError as exc:
                if exc.response is not None and exc.response.status_code == 404:
                    segment["tedim_text"] = _translate(segment["english_text"])
                else:
                    raise
        else:
            # Paragraph-by-paragraph: the 3B model is far more reliable on
            # 1-3 sentence chunks than on full sermons in one shot.
            paras = [p for p in segment["english_text"].split("\n\n") if p.strip()]
            segment["tedim_text"] = "\n\n".join(_translate(p) for p in paras)

        return segment

    except requests.RequestException as exc:
        raise self.retry(exc=exc)


@shared_task(name="tasks.narrate_tedim")
def narrate_tedim(session_token: str, segment_name: str, tedim_text: str) -> str:
    """TTS for Tedim text using the native local MMS-TTS Tedim checkpoint."""
    import narrator

    return narrator.narrate(
        session_token,
        segment_name,
        tedim_text,
        mode="edge_tts",
        language="td",
    )
