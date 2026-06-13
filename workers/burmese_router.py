"""
Burmese (Myanmar) LLM service — aivirtual.church FastAPI layer
===============================================================
Mount this router in workers/api.py:
    app.include_router(burmese_router.router)

Routes:
  POST /burmese/translate   {"text": "...", "direction": "en2my"|"my2en"}
  POST /burmese/generate    {"prompt": "...", "system": "..."}  (free-form Burmese prose)
  GET  /burmese/verse?ref=John+3:16  exact Myanmar Bible lookup (no LLM)

Redis cache (db 3) with 30-day TTL keeps repeated sermon segments instant.
Semaphore limits inference to one concurrent request — shared with the Tedim
router on the same 4-OCPU OCI ARM box.

Myanmar Unicode only — no Zawgyi, no romanization. The edge-tts my-MM voices
and the Padauk/Noto Sans Myanmar fonts on the frontend both expect Unicode.
"""

import asyncio
import hashlib
import os
import sys

import httpx
import redis.asyncio as aioredis
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

router = APIRouter(prefix="/burmese", tags=["burmese-llm"])

OLLAMA_URL = os.getenv("OLLAMA_URL", "http://127.0.0.1:11434/api/generate")
MODEL = os.getenv("OLLAMA_MODEL_MY", "burmese-myanmar")
CACHE_TTL = 60 * 60 * 24 * 30  # 30 days
_redis = aioredis.from_url("redis://127.0.0.1:6379/3", decode_responses=True)
_gpu_lock = asyncio.Semaphore(1)  # one inference at a time on ARM CPU


def _looks_like_myanmar(text: str) -> bool:
    letters = [ch for ch in text if ch.isalpha()]
    if not letters:
        return False
    myanmar = sum(1 for ch in letters if "\u1000" <= ch <= "\u109f" or "\uaa60" <= ch <= "\uaa7f" or "\ua9e0" <= ch <= "\ua9ff")
    khmer = sum(1 for ch in letters if "\u1780" <= ch <= "\u17ff")
    return myanmar >= max(3, int(len(letters) * 0.6)) and khmer == 0


def _validate_myanmar(text: str) -> str:
    if not _looks_like_myanmar(text):
        raise HTTPException(
            status_code=502,
            detail="Burmese model returned non-Myanmar text; disable OLLAMA_MODEL_MY or use a better Burmese model.",
        )
    return text


class TranslateIn(BaseModel):
    text: str
    direction: str = "en2my"  # en2my | my2en


class GenerateIn(BaseModel):
    prompt: str
    system: str | None = None
    max_tokens: int = 512


async def _ollama(prompt: str, system: str | None = None,
                  max_tokens: int = 512) -> str:
    payload = {
        "model": MODEL,
        "prompt": prompt,
        "stream": False,
        "options": {
            "temperature": 0.3,
            "top_p": 0.9,
            "num_ctx": 2048,
            "num_predict": max_tokens,
        },
    }
    if system:
        payload["system"] = system
    async with _gpu_lock:
        async with httpx.AsyncClient(timeout=600) as client:
            r = await client.post(OLLAMA_URL, json=payload)
            r.raise_for_status()
            return r.json()["response"].strip()


@router.post("/translate")
async def translate(body: TranslateIn):
    key = "burmese:tr:" + hashlib.sha1(
        f"{body.direction}|{body.text}".encode()).hexdigest()
    if cached := await _redis.get(key):
        return {"text": cached, "cached": True}

    prompt = (
        f"Translate to Burmese (Myanmar Unicode): {body.text}"
        if body.direction == "en2my"
        else f"Translate to English: {body.text}"
    )
    out = _validate_myanmar(await _ollama(prompt))
    await _redis.set(key, out, ex=CACHE_TTL)
    return {"text": out, "cached": False}


@router.post("/generate")
async def generate(body: GenerateIn):
    system = body.system or (
        "You are a Burmese (Myanmar) language assistant for a virtual church. "
        "Write devotional content in natural Myanmar Burmese using Myanmar Unicode "
        "script only. Never use Zawgyi encoding or romanized Burmese."
    )
    out = _validate_myanmar(await _ollama(body.prompt, system=system, max_tokens=body.max_tokens))
    return {"text": out}


@router.get("/verse")
async def verse(ref: str, lang: str = "my"):
    """
    Exact Bible verse lookup from the local Myanmar corpus (Judson 1835) — no LLM.
    Scripture bypasses inference entirely: deterministic, doctrinally safe, zero
    inference cost. Falls back to an HTTPException if the reference can't be resolved.
    """
    import bible_api  # workers/bible_api.py — always on sys.path via api.py

    try:
        text = bible_api.resolve(ref, lang=lang)
    except Exception as exc:
        raise HTTPException(status_code=404, detail=str(exc)) from exc

    if not text:
        raise HTTPException(status_code=404, detail=f"Verse not found: {ref}")

    return {"text": text, "ref": ref, "lang": lang}
