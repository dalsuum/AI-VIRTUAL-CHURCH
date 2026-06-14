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
            # Low temperature forces the model to pick highest-confidence tokens
            # rather than drifting into phonetic guessing of unknown Tedim words.
            "temperature": 0.3,
            "top_p": 0.85,
            "top_k": 40,
            # Penalise tokens the model has already used in this generation window;
            # this breaks the "heng eite ... heng eite" looping pattern.
            "repeat_penalty": 1.3,
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
    Rules applied in order:

    1. Minimum length (60 chars).
    2. Must contain core Tedim theological vocabulary.
    3. Must have ≥ 2 properly-placed sentence-final particles ('hi' / 'hen').
    4. At least 60% of sentences must end with a valid Tedim particle
       (hi / hen / in / amen). In genuine Tedim prose EVERY sentence ends this
       way; word salad has endings like 'te', 'eite', 'ka', 'zen', 'pai', 'tha'.
    5. Must NOT start any sentence/fragment with 'hi' or 'hen'.
    6. No consecutive repeated-word shuffling ('ka ka', 'nang nang', etc.).
    7. No repeated 3-word phrase appearing 3+ times (model-looping pattern).
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

    # In genuine Tedim every sentence ends with 'hi' (declarative), 'hen'
    # (prayer/blessing), 'in' (polite imperative), or 'amen'. Word-salad output
    # often has endings like 'te', 'eite', 'ka', 'zen', 'pai', 'tha'. Require
    # at least 60% of sentences follow the correct pattern.
    _VALID_FINALS = frozenset({"hi", "hen", "in", "amen"})
    sentences = re.split(r"[.!?]+", lower)
    sentences = [s.strip() for s in sentences if len(s.strip()) > 5]
    if len(sentences) >= 3:
        bad = sum(
            1 for s in sentences
            if not (s.split() and s.split()[-1] in _VALID_FINALS)
        )
        bad_ratio = bad / len(sentences)
        if bad_ratio > 0.40:
            raise HTTPException(
                status_code=502,
                detail=f"Too many non-Tedim sentence endings ({bad_ratio:.0%}); using fallback.",
            )

    # Reject if any line/fragment starts with 'hi' or 'hen' — always wrong.
    for line in clean.splitlines():
        stripped = line.strip().lower()
        if stripped.startswith("hi ") or stripped.startswith("hen ") or stripped in ("hi", "hen"):
            raise HTTPException(
                status_code=502,
                detail="Tedim output has sentence-initial 'hi'/'hen' (word salad); using fallback.",
            )

    # Reject obvious consecutive repeated-word shuffling ('ka ka', 'nang nang').
    if re.search(r"\b(\w{2,})\s+\1\b", lower):
        raise HTTPException(
            status_code=502,
            detail="Tedim output contains repeated-word patterns (word salad); using fallback.",
        )

    # Reject looping 3-gram patterns: the model gets stuck and repeats the same
    # short phrase 3+ times (e.g. "heng eite tha" × 4, "zo lhai zen hi" × 3).
    words = re.findall(r"\b\w+\b", lower)
    if len(words) >= 6:
        from collections import Counter
        trigrams = [" ".join(words[i:i+3]) for i in range(len(words) - 2)]
        top_count = Counter(trigrams).most_common(1)[0][1]
        if top_count >= 3:
            raise HTTPException(
                status_code=502,
                detail="Tedim output contains looping phrase patterns; using fallback.",
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
