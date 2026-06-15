"""
MMS-TTS service — native Tedim + Burmese narration.

Mount this router in workers/api.py. Models are loaded lazily on first use so the
Tedim/Burmese LLM routes keep booting even before the heavier TTS dependencies are
installed.
"""

from __future__ import annotations

import asyncio
import io
import json
import os
import re
from typing import Any

from fastapi import APIRouter, HTTPException
from fastapi.responses import Response
from pydantic import BaseModel

router = APIRouter(prefix="/tts", tags=["mms-tts"])

# The trusted root directory for fine-tuned models from the Voice Studio.
# Any path loaded from active_models.json will be verified against this base.
VOICE_STUDIO_BASE_DIR = "/opt/ai-church/backend/storage/app/voice-studio"


DEFAULT_MODELS = {
    "tedim": os.getenv("MMS_TTS_MODEL_TD", "facebook/mms-tts-ctd"),
    "burmese": os.getenv("MMS_TTS_MODEL_MY", "facebook/mms-tts-mya"),
}

_cache: dict[str, tuple[Any, Any]] = {}
_lock = asyncio.Semaphore(int(os.getenv("MMS_TTS_CONCURRENCY", "1")))


class TTSIn(BaseModel):
    text: str
    lang: str  # "tedim" | "burmese"
    seed: int = 42


@router.get("/languages")
async def languages() -> dict:
    return {"models": {lang: _model_name(lang) for lang in DEFAULT_MODELS}}


@router.post("/reload")
async def reload_models() -> dict:
    _cache.clear()
    return {"ok": True, "models": {lang: _model_name(lang) for lang in DEFAULT_MODELS}}


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
    model_name = _model_name(lang)
    _cache[lang] = (
        VitsModel.from_pretrained(model_name),
        AutoTokenizer.from_pretrained(model_name),
    )
    return _cache[lang]


def _model_name(lang: str) -> str:
    if os.getenv("MMS_TTS_AUTO_ACTIVE", "1") not in ("1", "true", "TRUE", "yes", "YES"):
        return DEFAULT_MODELS[lang]

    active_file = os.getenv(
        "MMS_TTS_ACTIVE_MODELS_FILE",
        os.path.join(VOICE_STUDIO_BASE_DIR, "active_models.json"),
    )
    lang_key = {"tedim": "td", "burmese": "my"}.get(lang, lang)
    try:
        with open(active_file, encoding="utf-8") as handle:
            models = json.load(handle)
        candidate_path = models.get(lang_key)
        if candidate_path and isinstance(candidate_path, str):
            # Security: Prevent path traversal. Ensure the model path is a legitimate,
            # resolved path safely within the expected voice studio directory.
            safe_base = os.path.realpath(VOICE_STUDIO_BASE_DIR)
            resolved_path = os.path.realpath(candidate_path)
            if resolved_path.startswith(safe_base) and os.path.isdir(resolved_path):
                return resolved_path
    except (OSError, json.JSONDecodeError):
        pass

    return DEFAULT_MODELS[lang]


@router.post("/speak")
async def speak(body: TTSIn) -> Response:
    text = body.text.strip()
    if body.lang not in DEFAULT_MODELS:
        raise HTTPException(400, f"lang must be one of {list(DEFAULT_MODELS)}")
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

    def _generate():
        with torch.no_grad():
            return model(**inputs).waveform[0].cpu().numpy()

    async with _lock:
        torch.manual_seed(body.seed)
        wav = await asyncio.to_thread(_generate)

    buf = io.BytesIO()
    scipy.io.wavfile.write(buf, rate=model.config.sampling_rate, data=wav)
    return Response(content=buf.getvalue(), media_type="audio/wav")
