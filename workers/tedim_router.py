"""
Tedim LLM service — aivirtual.church FastAPI layer
===================================================
Mount this router in workers/api.py:
    app.include_router(tedim_router.router)

Routes:
  POST /tedim/translate   {"text": "...", "direction": "en2zo"|"zo2en"}
  POST /tedim/generate    {"prompt": "...", "system": "..."}  (free-form Tedim prose)
  GET  /tedim/verse?ref=John+3:16  exact Tedim Bible lookup (no LLM)

Redis cache (db 2) with 30-day TTL keeps repeated sermon segments instant.
Semaphore limits inference to one concurrent request — the OCI ARM box
shares Gunicorn, Redis, and MySQL on the same 4-OCPU instance.
"""

import asyncio
import hashlib
import os
import re
import sys

import httpx
import redis.asyncio as aioredis
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

router = APIRouter(prefix="/tedim", tags=["tedim-llm"])

# English function words that never appear in genuine Tedim prose. A paragraph
# where these make up more than 30% of its words is English and must be removed.
_EN_MARKERS = frozenset({
    "you", "are", "have", "been", "the", "a", "an", "to", "of", "and", "is",
    "for", "that", "this", "with", "your", "by", "it", "was", "be", "as", "at",
    "from", "which", "all", "has", "will", "on", "but", "not", "they", "their",
    "we", "he", "she", "his", "her", "its", "our", "who", "or", "can", "do",
    "gives", "come", "today", "brings", "place", "grow", "part", "called", "done",
    "also", "so", "up", "out", "about", "into", "through", "there", "been",
    "church", "worship", "lord", "jesus", "god", "love", "gift", "life", "faith",
    "grateful", "thankful", "peace", "joy", "salvation", "presence", "community",
})


def _strip_english_paragraphs(text: str) -> str:
    """Remove paragraphs that are predominantly English from Tedim output."""
    paras = text.split("\n")
    clean = []
    for para in paras:
        stripped = para.strip()
        if not stripped:
            clean.append(para)
            continue
        words = re.findall(r"\b[a-z]+\b", stripped.lower())
        if len(words) < 4:
            clean.append(para)
            continue
        english_hits = sum(1 for w in words if w in _EN_MARKERS)
        if english_hits / len(words) > 0.30:
            continue  # English paragraph — drop it
        clean.append(para)
    result = "\n".join(clean).strip()
    # If we stripped everything (degenerate model output), return original
    return result if result else text

OLLAMA_URL = os.getenv("OLLAMA_URL", "http://127.0.0.1:11434/api/generate")
MODEL = os.getenv("OLLAMA_MODEL_TD", "tedim-zolai")
CACHE_TTL = 60 * 60 * 24 * 30  # 30 days
_redis = aioredis.from_url("redis://127.0.0.1:6379/2", decode_responses=True)
_gpu_lock = asyncio.Semaphore(1)  # one inference at a time on ARM CPU


class TranslateIn(BaseModel):
    text: str
    direction: str = "en2zo"  # en2zo | zo2en


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
            "temperature": 0.75,
            "top_p": 0.95,
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
    key = "tedim:tr:" + hashlib.sha1(
        f"{body.direction}|{body.text}".encode()).hexdigest()
    if cached := await _redis.get(key):
        return {"text": cached, "cached": True}

    prompt = (
        f"Translate to Tedim (Zolai): {body.text}"
        if body.direction == "en2zo"
        else f"Translate to English: {body.text}"
    )
    out = await _ollama(prompt)
    await _redis.set(key, out, ex=CACHE_TTL)
    return {"text": out, "cached": False}


def _validate_tedim(text: str) -> str:
    """Reject degenerate model output so llm_engine falls back to hardcoded Tedim.

    The 1B model produces word salad that passes a naive length+vocabulary check.
    These stricter rules reject output that looks like Tedim words but isn't
    grammatically coherent prose:

    1. Minimum length (60 chars).
    2. Must contain core Tedim theological vocabulary.
    3. Must have at least 2 properly-placed sentence-final particles ('hi' / 'hen')
       — genuine Tedim prose ends every declarative with 'hi' and every prayer with
       'hen'. Word salad strings these randomly in the middle of fragments.
    4. Must NOT start any sentence/fragment with 'hi' or 'hen' — those are
       sentence-FINAL; leading them is always wrong.
    5. No obvious word repetition (e.g. 'ka ka', 'nang nang') that marks random
       token shuffling.
    """
    _TEDIM_MARKERS = ("pasian", "topa", "zeisu", "krist",
                      "lungdamna", "thungetna", "zangtal", "ka ", "na ", " in ")
    clean = text.strip()
    lower = clean.lower()

    if len(clean) < 60:
        raise HTTPException(
            status_code=502,
            detail="Tedim model output too short; using fallback content.",
        )
    if not any(marker in lower for marker in _TEDIM_MARKERS):
        raise HTTPException(
            status_code=502,
            detail="Tedim model output lacks Tedim markers; using fallback content.",
        )

    # Require at least 2 proper sentence-final 'hi' or 'hen' placements.
    # Count occurrences of the word immediately before a sentence boundary.
    terminal_hi = (
        lower.count(" hi\n") + lower.count(" hi.")
        + lower.count(" hi!") + lower.count(" hi,")
        + lower.count(" hen\n") + lower.count(" hen.")
        + lower.count(" hen!")
    )
    if lower.rstrip().endswith((" hi", " hen")):
        terminal_hi += 1
    if terminal_hi < 2:
        raise HTTPException(
            status_code=502,
            detail="Tedim output lacks proper sentence-final particles; using fallback.",
        )

    # Reject if any line/fragment starts with 'hi' or 'hen' — always wrong.
    for line in clean.splitlines():
        stripped = line.strip().lower()
        if stripped.startswith("hi ") or stripped.startswith("hen ") or stripped in ("hi", "hen"):
            raise HTTPException(
                status_code=502,
                detail="Tedim output has sentence-initial 'hi'/'hen' (word salad); using fallback.",
            )

    # Reject obvious repeated-word token shuffling ('ka ka', 'nang nang', etc.).
    import re as _re
    if _re.search(r"\b(\w{2,})\s+\1\b", lower):
        raise HTTPException(
            status_code=502,
            detail="Tedim output contains repeated-word patterns (word salad); using fallback.",
        )

    return clean


_TEDIM_SYSTEM_DEFAULT = (
    "LANGUAGE LAW: Write EVERY sentence in Tedim Chin (Zolai / Zomi pau) ONLY. "
    "ZERO English. No explanations or translations. "
    "You are a Tedim (Zolai) worship assistant for a virtual church. "
    "GRAMMAR — SOV: the verb always comes at the END of the sentence. "
    "Subject marker 'in' follows the subject (e.g. 'Pasian in' = God [as subject]). "
    "SENTENCE ENDINGS: every declarative sentence MUST end with 'hi'. "
    "Every prayer or blessing sentence MUST end with 'hen'. "
    "Never start a sentence with 'hi' or 'hen'. "
    "PRONOUNS: ka (I/my), nang/na (you/your), amah (he/she), eite (we), amaute (they). "
    "TENSE: past = verb+'khin hi'; future = verb+'ding hi'; present = verb+'hi'; negation = verb+'lo hi'. "
    "CORRECT EXAMPLES — follow these patterns exactly: "
    "'Pasian in na it hi.' = God loves you. "
    "'Topa in na thungna hong za hi.' = The Lord hears your prayer. "
    "'Zeisu in zangtal hong piak hi.' = Jesus gives salvation. "
    "'Kha Siangtho in hong makaih hen.' = May the Holy Spirit guide you. "
    "'Na kiangah Topa in om hi.' = The Lord is near you. "
    "OPENING PRAYER PATTERN (follow this): "
    "'Aw Topa Pasian, tuni in ka lungtang hong khol in na kiangah ka hong pai hi. "
    "Ka lungkhamna pen nang kianga hi. Na lungdamna in hong kem in. "
    "Zeisu Krist min in ka thungen hi. Amen.' "
    "BENEDICTION PATTERN (follow this): "
    "'Topa Pasian nopna in na lungtang kem hen. "
    "Zeisu Krist itna in hong thahat sak hen. "
    "Kha Siangtho in na lam hong makaih hen. Amen.'"
)


@router.post("/generate")
async def generate(body: GenerateIn):
    system = body.system or _TEDIM_SYSTEM_DEFAULT
    out = _validate_tedim(_strip_english_paragraphs(
        await _ollama(body.prompt, system=system, max_tokens=body.max_tokens)
    ))
    return {"text": out}


@router.get("/verse")
async def verse(ref: str, lang: str = "td"):
    """
    Exact Bible verse lookup from the local Tedim corpus — no LLM involved.
    Scripture bypasses inference entirely: deterministic, doctrinally safe,
    zero inference cost.  Falls back to an HTTPException if the reference
    can't be resolved (e.g. the 1932 Lai Siangtho doesn't cover that book).
    """
    import bible_api  # workers/bible_api.py — always on sys.path via api.py

    try:
        text = bible_api.resolve(ref, lang=lang)
    except Exception as exc:
        raise HTTPException(status_code=404, detail=str(exc)) from exc

    if not text:
        raise HTTPException(status_code=404, detail=f"Verse not found: {ref}")

    return {"text": text, "ref": ref, "lang": lang}
