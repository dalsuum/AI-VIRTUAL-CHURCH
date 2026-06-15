"""
NLLB-200 translation service — aivirtual.church FastAPI layer
=============================================================
Local Burmese translation backend using facebook/nllb-200-distilled-600M.

Why this exists: the fine-tuned Ollama `burmese-myanmar` model often emits
word-salad (see memory: burmese_model_unusable_for_lyrics). NLLB translates the
already-good English worship prose into fluent Myanmar instead. Tested 2026-06-15:
Burmese (`mya_Mymr`) is fluent; Tedim (`tdt_Latn`) is NOT usable, so this service
is used for Burmese only — Tedim stays on Ollama `tedim-zolai`.

Mount in workers/api.py:
    app.include_router(nllb_router.router)
…but PyTorch inference competes with Ollama for CPU, so it is served on its own
port (8004) via nllb_api.py, mirroring the MMS-TTS split on 8003.

Route:
  POST /nllb/translate  {"text": "...", "src_lang": "eng_Latn", "tgt_lang": "mya_Mymr"}
      -> {"translation": "...", "src_lang": "...", "tgt_lang": "..."}

Redis cache (db 4, 30-day TTL) keeps repeated segments instant. The model loads
lazily on first request so the worker boots fast.
"""

import asyncio
import hashlib
import os
import re
import sys

import redis.asyncio as aioredis
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

router = APIRouter(prefix="/nllb", tags=["nllb-translate"])

MODEL_ID = os.getenv("NLLB_MODEL_ID", "facebook/nllb-200-distilled-600M")
MAX_NEW_TOKENS = int(os.getenv("NLLB_MAX_NEW_TOKENS", "512"))
CACHE_TTL = 60 * 60 * 24 * 30  # 30 days
_redis = aioredis.from_url("redis://127.0.0.1:6379/4", decode_responses=True)
_lock = asyncio.Semaphore(1)  # one inference at a time on the shared CPU box

_model = None
_tokenizer = None


def _load():
    """Lazily load the model once, off the event loop's hot path."""
    global _model, _tokenizer
    if _model is not None:
        return
    import torch
    from transformers import AutoModelForSeq2SeqLM, AutoTokenizer

    print(f"[nllb] loading {MODEL_ID} ...", flush=True)
    _tokenizer = AutoTokenizer.from_pretrained(MODEL_ID)
    _model = AutoModelForSeq2SeqLM.from_pretrained(MODEL_ID, torch_dtype=torch.float32)
    _model.eval()
    print("[nllb] model ready", flush=True)


# NLLB emits Myanmar with a space between every syllable cluster and sometimes a
# stray trailing dash. Real Myanmar text is written without those intra-word
# spaces, and edge-tts/MMS-TTS choke on them, so normalize before returning.
_MY_SPACE = re.compile(r"(?<=[က-႟])\s+(?=[က-႟])")


def _clean_myanmar(text: str) -> str:
    text = _MY_SPACE.sub("", text)
    text = text.replace(" ။", "။").replace(" ၊", "၊")
    return text.strip().strip("-").strip()


def _translate_sync(text: str, src_lang: str, tgt_lang: str) -> str:
    import torch

    _load()
    bos = _tokenizer.convert_tokens_to_ids(tgt_lang)
    if bos is None or bos == _tokenizer.unk_token_id:
        raise HTTPException(status_code=400, detail=f"unknown tgt_lang {tgt_lang!r}")
    _tokenizer.src_lang = src_lang
    enc = _tokenizer(text, return_tensors="pt", truncation=True, max_length=512)
    with torch.inference_mode():
        out = _model.generate(
            **enc,
            forced_bos_token_id=bos,
            max_new_tokens=MAX_NEW_TOKENS,
            num_beams=4,
        )
    decoded = _tokenizer.batch_decode(out, skip_special_tokens=True)[0].strip()
    return _clean_myanmar(decoded) if tgt_lang == "mya_Mymr" else decoded


class TranslateIn(BaseModel):
    text: str
    src_lang: str = "eng_Latn"
    tgt_lang: str = "mya_Mymr"


@router.post("/translate")
async def translate(body: TranslateIn):
    text = (body.text or "").strip()
    if not text:
        raise HTTPException(status_code=400, detail="missing 'text'")

    key = "nllb:" + hashlib.sha256(
        f"{MODEL_ID}|{body.src_lang}|{body.tgt_lang}|{text}".encode()
    ).hexdigest()
    cached = await _redis.get(key)
    if cached is not None:
        return {"translation": cached, "src_lang": body.src_lang, "tgt_lang": body.tgt_lang, "cached": True}

    async with _lock:
        loop = asyncio.get_event_loop()
        translation = await loop.run_in_executor(
            None, _translate_sync, text, body.src_lang, body.tgt_lang
        )

    await _redis.set(key, translation, ex=CACHE_TTL)
    return {"translation": translation, "src_lang": body.src_lang, "tgt_lang": body.tgt_lang}
