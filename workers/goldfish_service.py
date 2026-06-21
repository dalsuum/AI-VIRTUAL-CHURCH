"""
Goldfish LLM service — native Mizo (lus) & Paite (pck) text generation.
=======================================================================
Backs the two Chin/Zo languages that have NO instruction-tuned Ollama model
and NO upstream MMS-TTS voice with the goldfish-models monolingual GPT-2-style
causal LMs from Hugging Face:

    Mizo (Lushai)  lus  → goldfish-models/lus_latn_full   (~100 MB)
    Paite (Zomi)   pck  → goldfish-models/pck_latn_full    (~5 MB)

Goldfish models are *completion-only* (no chat template / system prompt), so we
prime them with a short native-language seed prompt and let them continue. The
chin_router imports `generate_text()` directly (in-process) for /mizo and /paite
generation; the router below additionally exposes the models over HTTP for
status checks and standalone use.

Loaded lazily on first use (mirrors mms_tts_service) so the rest of api.py boots
even before transformers/torch are installed. Each language's narrator can be
turned off from the admin console — AdminController writes a Redis flag
(`ai:narration_<iso>`) that `is_enabled()` consults; when off, callers get a 502
and fall back to curated content, the same contract as the other Chin routers.
"""

from __future__ import annotations

import asyncio
import os
from typing import Any

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel

router = APIRouter(prefix="/goldfish", tags=["goldfish-llm"])

# iso code → Hugging Face goldfish model id. Overridable via env for pinning a
# local snapshot or a fine-tuned successor.
DEFAULT_MODELS: dict[str, str] = {
    "lus": os.getenv("GOLDFISH_MODEL_LUS", "goldfish-models/lus_latn_full"),
    "pck": os.getenv("GOLDFISH_MODEL_PCK", "goldfish-models/pck_latn_full"),
}

# Human names for messages, keyed by iso.
_NAMES = {"lus": "Mizo (Lushai)", "pck": "Paite (Zomi)"}

_cache: dict[str, tuple[Any, Any]] = {}
# Goldfish models are small but the host runs a single inference at a time to
# avoid CPU thrash alongside the Ollama and MMS-TTS workers.
_lock = asyncio.Semaphore(int(os.getenv("GOLDFISH_CONCURRENCY", "1")))

# Generation bounds. Goldfish context is short (GPT-2 sized), so keep prompts and
# outputs modest.
_MAX_NEW_TOKENS = int(os.getenv("GOLDFISH_MAX_NEW_TOKENS", "256"))


class GenerateIn(BaseModel):
    lang: str          # "lus" | "pck"
    prompt: str
    max_tokens: int = _MAX_NEW_TOKENS


def _redis():
    import redis as _redis_mod

    return _redis_mod.from_url(
        os.getenv("REDIS_URL", "redis://localhost:6379/0"), decode_responses=True
    )


def is_enabled(iso: str) -> bool:
    """Whether the admin has the goldfish narrator for `iso` switched on.

    Defaults to enabled when the flag is unset or Redis is unreachable so a
    fresh install is functional; the admin can explicitly disable it.
    """
    try:
        raw = _redis().get(f"ai:narration_{iso}")
    except Exception:
        return True
    return raw is None or raw == "1"


def _missing_dependency(exc: ImportError) -> HTTPException:
    return HTTPException(
        status_code=503,
        detail="Goldfish LLM dependencies are not installed. Install transformers and torch.",
    )


def _model_name(iso: str) -> str:
    if iso not in DEFAULT_MODELS:
        raise HTTPException(400, f"lang must be one of {list(DEFAULT_MODELS)}")
    return DEFAULT_MODELS[iso]


def _load(iso: str):
    if iso in _cache:
        return _cache[iso]
    try:
        import torch
        from transformers import AutoModelForCausalLM, AutoTokenizer
    except ImportError as exc:
        raise _missing_dependency(exc) from exc

    torch.set_num_threads(int(os.getenv("GOLDFISH_TORCH_THREADS", "3")))
    model_name = _model_name(iso)
    tokenizer = AutoTokenizer.from_pretrained(model_name)
    model = AutoModelForCausalLM.from_pretrained(model_name)
    model.eval()
    _cache[iso] = (model, tokenizer)
    return _cache[iso]


def _infer(iso: str, prompt: str, max_tokens: int) -> str:
    import torch

    model, tokenizer = _load(iso)
    inputs = tokenizer(prompt, return_tensors="pt", truncation=True, max_length=512)
    with torch.no_grad():
        out = model.generate(
            **inputs,
            max_new_tokens=max(16, min(int(max_tokens), _MAX_NEW_TOKENS)),
            do_sample=True,
            temperature=0.7,
            top_p=0.9,
            repetition_penalty=1.3,
            no_repeat_ngram_size=3,
            pad_token_id=tokenizer.eos_token_id or tokenizer.pad_token_id,
        )
    text = tokenizer.decode(out[0], skip_special_tokens=True)
    # Goldfish echoes the prompt; return only the freshly generated continuation.
    if text.startswith(prompt):
        text = text[len(prompt):]
    return text.strip()


async def generate_text(iso: str, prompt: str, max_tokens: int = _MAX_NEW_TOKENS) -> str:
    """In-process generation entry point used by chin_router.

    Raises HTTPException(502) when the narrator is disabled so the caller falls
    back to curated content (same contract as the Ollama-backed routers).
    """
    if not is_enabled(iso):
        raise HTTPException(
            status_code=502,
            detail=f"{_NAMES.get(iso, iso)} goldfish narrator is disabled; using fallback content.",
        )
    async with _lock:
        return await asyncio.to_thread(_infer, iso, prompt, max_tokens)


@router.get("/languages")
async def languages() -> dict:
    return {
        "models": {
            iso: {"model": _model_name(iso), "enabled": is_enabled(iso)}
            for iso in DEFAULT_MODELS
        }
    }


@router.post("/reload")
async def reload_models() -> dict:
    _cache.clear()
    return {"ok": True, "models": DEFAULT_MODELS}


@router.post("/generate")
async def generate(body: GenerateIn) -> dict:
    if body.lang not in DEFAULT_MODELS:
        raise HTTPException(400, f"lang must be one of {list(DEFAULT_MODELS)}")
    text = await generate_text(body.lang, body.prompt, body.max_tokens)
    return {"text": text, "lang": body.lang}
