"""
MMS-TTS service — native Tedim + Burmese narration.

Mount this router in workers/api.py. Models are loaded lazily on first use so the
Tedim/Burmese LLM routes keep booting even before the heavier TTS dependencies are
installed.
"""

from __future__ import annotations

import asyncio
import io
import os
import re
from typing import Any

from fastapi import APIRouter, HTTPException
from fastapi.responses import Response
from pydantic import BaseModel

router = APIRouter(prefix="/tts", tags=["mms-tts"])

MODELS = {
    "tedim": os.getenv("MMS_TTS_MODEL_TD", "facebook/mms-tts-ctd"),
    "burmese": os.getenv("MMS_TTS_MODEL_MY", "facebook/mms-tts-mya"),
}

_cache: dict[str, tuple[Any, Any]] = {}
_lock = asyncio.Semaphore(int(os.getenv("MMS_TTS_CONCURRENCY", "1")))


class TTSIn(BaseModel):
    text: str
    lang: str  # "tedim" | "burmese"
    seed: int = 42


def _missing_dependency(exc: ImportError) -> HTTPException:
    return HTTPException(
        status_code=503,
        detail="MMS TTS dependencies are not installed. Install transformers, torch, and scipy.",
    )


def _legacy_myanmar_probability(text: str) -> float:
    # Conservative guard for likely legacy-encoded Myanmar. We never convert text
    # into legacy encoding; Burmese narration accepts Myanmar Unicode only.
    suspicious = len(re.findall(r"[\u1031\u103B-\u103E][\u1000-\u1021]", text))
    myanmar = len(re.findall(r"[\u1000-\u109F]", text))
    return suspicious / max(1, myanmar)


def _load(lang: str):
    if lang in _cache:
        return _cache[lang]

    try:
        import torch
        from transformers import AutoTokenizer, VitsModel
    except ImportError as exc:
        raise _missing_dependency(exc) from exc

    torch.set_num_threads(int(os.getenv("MMS_TTS_TORCH_THREADS", "3")))
    model_name = MODELS[lang]
    _cache[lang] = (
        VitsModel.from_pretrained(model_name),
        AutoTokenizer.from_pretrained(model_name),
    )
    return _cache[lang]


@router.post("/speak")
async def speak(body: TTSIn) -> Response:
    text = body.text.strip()
    if body.lang not in MODELS:
        raise HTTPException(400, f"lang must be one of {list(MODELS)}")
    if not text:
        raise HTTPException(400, "text is required")
    if body.lang == "burmese" and _legacy_myanmar_probability(text) > 0.95:
        raise HTTPException(422, "Legacy Myanmar encoding rejected — Myanmar Unicode only")

    try:
        import scipy.io.wavfile
        import torch
    except ImportError as exc:
        raise _missing_dependency(exc) from exc

    model, tokenizer = _load(body.lang)
    inputs = tokenizer(text, return_tensors="pt")

    async with _lock:
        torch.manual_seed(body.seed)
        with torch.no_grad():
            wav = model(**inputs).waveform[0].cpu().numpy()

    buf = io.BytesIO()
    scipy.io.wavfile.write(buf, rate=model.config.sampling_rate, data=wav)
    return Response(content=buf.getvalue(), media_type="audio/wav")
