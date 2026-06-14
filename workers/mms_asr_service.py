"""
MMS-ASR service — Tedim + Burmese speech-to-text.

This is intentionally separate from fine-tuning. Voice Studio can call it to
spot-check a fresh recording or run a QC loop, while the exported dataset remains
the source material for a later MMS/VITS fine-tune.
"""

from __future__ import annotations

import asyncio
import os
import tempfile
from typing import Any

from fastapi import APIRouter, File, Form, HTTPException, UploadFile

router = APIRouter(prefix="/stt", tags=["mms-stt"])

LANGS = {
    "tedim": "ctd",
    "td": "ctd",
    "burmese": "mya",
    "my": "mya",
}

MODEL_NAME = os.getenv("MMS_ASR_MODEL", "facebook/mms-1b-all")

_cache: dict[str, Any] = {}
_lock = asyncio.Semaphore(int(os.getenv("MMS_ASR_CONCURRENCY", "1")))


def _missing_dependency(exc: ImportError) -> HTTPException:
    return HTTPException(
        status_code=503,
        detail=(
            "MMS ASR dependencies are not installed. Install transformers, torch, "
            "python-multipart, and ensure ffmpeg is available."
        ),
    )


def _target_lang(lang: str) -> str:
    code = LANGS.get(lang.strip().lower())
    if not code:
        raise HTTPException(400, f"lang must be one of {sorted(LANGS)}")
    return code


def _load(target_lang: str):
    if target_lang in _cache:
        return _cache[target_lang]

    try:
        import torch
        from transformers import pipeline
    except ImportError as exc:
        raise _missing_dependency(exc) from exc

    torch.set_num_threads(int(os.getenv("MMS_ASR_TORCH_THREADS", "3")))
    _cache[target_lang] = pipeline(
        "automatic-speech-recognition",
        model=MODEL_NAME,
        model_kwargs={
            "target_lang": target_lang,
            "ignore_mismatched_sizes": True,
        },
    )
    return _cache[target_lang]


@router.get("/languages")
async def languages() -> dict:
    return {
        "model": MODEL_NAME,
        "languages": {
            "tedim": "ctd",
            "burmese": "mya",
        },
    }


@router.post("/transcribe")
async def transcribe(
    lang: str = Form(...),
    audio: UploadFile = File(...),
) -> dict:
    target_lang = _target_lang(lang)
    suffix = os.path.splitext(audio.filename or "")[1] or ".webm"

    try:
        data = await audio.read()
        if not data:
            raise HTTPException(400, "audio is required")

        with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
            tmp.write(data)
            tmp_path = tmp.name

        pipe = _load(target_lang)
        async with _lock:
            result = await asyncio.to_thread(pipe, tmp_path)
    except ImportError as exc:
        raise _missing_dependency(exc) from exc
    finally:
        if "tmp_path" in locals():
            try:
                os.unlink(tmp_path)
            except OSError:
                pass

    return {
        "text": str(result.get("text", "")).strip(),
        "lang": target_lang,
        "model": MODEL_NAME,
    }
