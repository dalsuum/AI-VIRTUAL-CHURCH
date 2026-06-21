"""
Chin/Zo LLM services — aivirtual.church FastAPI layer
======================================================
Config-driven sibling of tedim_router.py covering four additional
Chin/Zo languages, each backed by its own Ollama model (llama3.2:1b +
per-language Modelfile system prompt):

    Falam (Lai)   cfm  → ollama:   falam-lai                    prefix: /falam
    Hakha (Lai)   cnh  → ollama:   hakha-lai                    prefix: /hakha
    Mizo (Lushai) lus  → goldfish: goldfish-models/lus_latn_full prefix: /mizo
    Paite (Zomi)  pck  → goldfish: goldfish-models/pck_latn_full prefix: /paite

Falam/Hakha use instruction-tuned Ollama models; Mizo/Paite have no such model
upstream, so they are backed by the goldfish-models monolingual LMs served
in-process by goldfish_service.py (engine: "goldfish" in their LANGS config).

Mount in workers/api.py:
    from chin_router import ROUTERS as CHIN_ROUTERS
    for r in CHIN_ROUTERS:
        app.include_router(r)

Routes per language:
  POST /<lang>/translate   {"text": "...", "direction": "en2xx"|"xx2en"}
  POST /<lang>/generate    {"prompt": "...", "system": "..."}
  GET  /<lang>/verse?ref=John+3:16   exact Bible lookup (no LLM)

Each language shares one Ollama inference semaphore — the host runs a single
1B model at a time, so concurrent requests across languages would thrash the
CPU and swap. Redis (db 2) caches translations for 30 days.

Validation here is deliberately lighter than the Tedim router: it enforces
length, language vocabulary markers, and rejects the degenerate looping /
repeated-word output the 1B base model produces, but does NOT impose the
Tedim sentence-final-particle ('hi'/'hen') grammar, which is specific to
Tedim and does not apply to Lai/Mizo/Paite. Failing validation raises 502 so
llm_engine falls back to curated content — same contract as Tedim.
"""

import asyncio
import hashlib
import os
import re
import sys
from collections import Counter

import httpx
import redis.asyncio as aioredis
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

OLLAMA_URL = os.getenv("OLLAMA_URL", "http://127.0.0.1:11434/api/generate")
CACHE_TTL = 60 * 60 * 24 * 30  # 30 days
_redis = aioredis.from_url("redis://127.0.0.1:6379/2", decode_responses=True)
# One inference at a time across ALL Chin languages AND shared with nothing
# else here; the Tedim router holds its own separate semaphore but the host
# only has one Ollama process, so keep concurrency at 1 to avoid CPU thrash.
_gpu_lock = asyncio.Semaphore(1)

_REPEATED_WORD_RE = re.compile(r"\b(\w{2,})\s+\1\b")
_WORD_RE = re.compile(r"\b\w+\b")

# English function/content words that never appear in genuine Chin/Zo prose.
# A paragraph that is >30% these words is English and is dropped.
_EN_MARKERS = frozenset({
    "you", "are", "have", "been", "the", "a", "an", "to", "of", "and", "is",
    "for", "that", "this", "with", "your", "by", "it", "was", "be", "as", "at",
    "from", "which", "all", "has", "will", "on", "but", "not", "they", "their",
    "we", "he", "she", "his", "her", "its", "our", "who", "or", "can", "do",
    "gives", "come", "today", "brings", "place", "grow", "part", "called", "done",
    "also", "so", "up", "out", "about", "into", "through", "there",
    "church", "worship", "lord", "jesus", "god", "love", "gift", "life", "faith",
    "grateful", "thankful", "peace", "joy", "salvation", "presence", "community",
})


# ---------------------------------------------------------------------------
# Per-language configuration. `markers` are lowercase substrings that genuine
# worship prose in that language is overwhelmingly likely to contain; `bible`
# is the bible_api lang key for verse lookup; `system` is the default free-form
# system prompt used when the caller does not supply one.
# ---------------------------------------------------------------------------
LANGS: dict[str, dict] = {
    "falam": {
        "iso": "cfm",
        "name": "Falam Chin (Laiholh)",
        "model_env": "OLLAMA_MODEL_CFM",
        "model": "falam-lai",
        "bible": "cfm",
        "markers": ("pathian", "bawipa", "jesuh", "khrih", "thlacamnak",
                    "khrihfabu", "zaangfahnak", "lungthin", "ka ", "na "),
        "system": (
            "LANGUAGE LAW: Write EVERY sentence in Falam Chin (Laiholh) ONLY. "
            "ZERO English. No explanations or translations. "
            "You are a Falam worship assistant for a virtual church. "
            "Falam is a Lai language, NOT Mizo, NOT Tedim, NOT Zomi — never use "
            "their words. SOV grammar: the verb comes at the END of the sentence. "
            "VOCABULARY: Pathian (God), Bawipa (the Lord), Jesuh Khrih (Jesus "
            "Christ), Thlarau Thiang (Holy Spirit), thlacamnak (prayer), "
            "Khrihfabu (church), zaangfahnak (grace), dawtnak (love), remnak "
            "(peace), lungthin (heart), nunnak (life). End prayers with 'Amen'. "
            "Keep sentences short and reverent."
        ),
    },
    "hakha": {
        "iso": "cnh",
        "name": "Hakha Chin (Laiholh)",
        "model_env": "OLLAMA_MODEL_CNH",
        "model": "hakha-lai",
        "bible": "cnh",
        "markers": ("pathian", "bawipa", "jesuh", "khrih", "thlacamnak",
                    "khrihfabu", "velnak", "lungthin", "ka ", "na "),
        "system": (
            "LANGUAGE LAW: Write EVERY sentence in Hakha Chin (Laiholh) ONLY. "
            "ZERO English. No explanations or translations. "
            "You are a Hakha worship assistant for a virtual church. "
            "Hakha is a Lai language, NOT Mizo, NOT Tedim, NOT Falam — never use "
            "their words. SOV grammar: the verb comes at the END of the sentence. "
            "VOCABULARY: Pathian (God), Bawipa (the Lord), Jesuh Khrih (Jesus "
            "Christ), Thlarau Thiang (Holy Spirit), thlacamnak (prayer), "
            "Khrihfabu (church), velnak (grace), dawtnak (love), daihnak (peace), "
            "lungthin (heart), nunnak (life). End prayers with 'Amen'. "
            "Keep sentences short and reverent."
        ),
    },
    "mizo": {
        "iso": "lus",
        "name": "Mizo (Lushai)",
        # Mizo has no instruction-tuned Ollama model; it is backed by the
        # goldfish-models/lus_latn_full monolingual LM via goldfish_service.
        "engine": "goldfish",
        "model_env": "OLLAMA_MODEL_LUS",
        "model": "mizo-lushai",
        "bible": "lus",
        "markers": ("pathian", "lalpa", "isua", "krista", "tawngtaina",
                    "kohhran", "khawngaihna", "thinlung", "ka ", "i "),
        "system": (
            "LANGUAGE LAW: Write EVERY sentence in Mizo (Mizo tawng) ONLY. "
            "ZERO English. No explanations or translations. "
            "You are a Mizo worship assistant for a virtual church. "
            "Mizo is NOT Tedim, NOT Hakha, NOT Falam, NOT Paite — never use their "
            "words. SOV grammar: the verb comes at the END of the sentence. "
            "VOCABULARY: Pathian (God), Lalpa (the Lord), Isua Krista (Jesus "
            "Christ), Thlarau Thianghlim (Holy Spirit), tawngtaina (prayer), "
            "kohhran (church), khawngaihna (grace), hmangaihna (love), remna "
            "(peace), thinlung (heart), nunna (life). End prayers with 'Amen'. "
            "Keep sentences short and reverent."
        ),
    },
    "paite": {
        "iso": "pck",
        "name": "Paite (Zomi)",
        # Paite is backed by goldfish-models/pck_latn_full via goldfish_service.
        "engine": "goldfish",
        "model_env": "OLLAMA_MODEL_PCK",
        "model": "paite-zomi",
        "bible": "pck",
        "markers": ("pasian", "toupa", "zeisu", "khris", "thumna", "pawlpi",
                    "hotdamna", "lungtang", "ka ", "na "),
        "system": (
            "LANGUAGE LAW: Write EVERY sentence in Paite ONLY. ZERO English. "
            "No explanations or translations. "
            "You are a Paite (Zomi) worship assistant for a virtual church. "
            "Paite is close to Tedim but NOT identical, and NOT Mizo, NOT Hakha, "
            "NOT Falam — use Paite words. SOV grammar: the verb comes at the END "
            "of the sentence. "
            "VOCABULARY: Pasian (God), Toupa (the Lord), Zeisu Khris (Jesus "
            "Christ), Kha Siangthou (Holy Spirit), thumna (prayer), pawlpi "
            "(church), hotdamna (salvation), itna (love), lemna (peace), "
            "lungtang (heart), nuntakna (life). End prayers with 'Amen'. "
            "Keep sentences short and reverent."
        ),
    },
}


class TranslateIn(BaseModel):
    text: str
    direction: str = "en2xx"  # en2xx | xx2en


class GenerateIn(BaseModel):
    prompt: str
    system: str | None = None
    max_tokens: int = 512


def _model_name(cfg: dict) -> str:
    return os.getenv(cfg["model_env"], cfg["model"])


def _strip_english_paragraphs(text: str) -> str:
    """Remove paragraphs that are predominantly English from the output."""
    clean = []
    for para in text.split("\n"):
        stripped = para.strip()
        if not stripped:
            clean.append(para)
            continue
        # Drop parenthetical English meta-commentary / translation notes the 1B
        # model appends, e.g. "(Thank you Lord ...)" or "(Note: I've used ...)".
        if re.fullmatch(r"\(.*[a-z]{3,}.*\)\.?", stripped) or \
                re.match(r"\(?(note|translation)\b", stripped.lower()):
            continue
        words = re.findall(r"\b[a-z]+\b", stripped.lower())
        if len(words) < 4:
            clean.append(para)
            continue
        if sum(1 for w in words if w in _EN_MARKERS) / len(words) > 0.30:
            continue  # English paragraph — drop it
        clean.append(para)
    result = "\n".join(clean).strip()
    return result if result else text


def _validate(text: str, cfg: dict) -> str:
    """Reject degenerate 1B output so llm_engine falls back to curated content.

    Rules (language-agnostic):
      1. Minimum length (60 chars).
      2. Must contain at least one language vocabulary marker.
      3. No consecutive repeated-word shuffling ('ka ka', 'nang nang').
      4. No 3-word phrase repeated 3+ times (model-looping pattern).
    """
    clean = text.strip()
    lower = clean.lower()

    if len(clean) < 60:
        raise HTTPException(
            status_code=502,
            detail=f"{cfg['name']} output too short; using fallback content.",
        )
    if not any(marker in lower for marker in cfg["markers"]):
        raise HTTPException(
            status_code=502,
            detail=f"{cfg['name']} output lacks language markers; using fallback content.",
        )
    if _REPEATED_WORD_RE.search(lower):
        raise HTTPException(
            status_code=502,
            detail=f"{cfg['name']} output has repeated-word patterns (word salad); using fallback.",
        )
    words = _WORD_RE.findall(lower)
    if len(words) >= 6:
        trigrams = [" ".join(words[i:i + 3]) for i in range(len(words) - 2)]
        if Counter(trigrams).most_common(1)[0][1] >= 3:
            raise HTTPException(
                status_code=502,
                detail=f"{cfg['name']} output contains looping phrase patterns; using fallback.",
            )
    return clean


async def _goldfish(cfg: dict, prompt: str, system: str | None = None,
                    max_tokens: int = 512) -> str:
    """Generate via the in-process goldfish monolingual LM (Mizo/Paite).

    Goldfish is completion-only, so the system prompt is folded into a short
    native-language preamble and the model continues from there.
    """
    import goldfish_service  # mounted alongside this router in api.py

    seed = f"{system}\n\n{prompt}" if system else prompt
    return await goldfish_service.generate_text(cfg["iso"], seed, max_tokens)


async def _infer(cfg: dict, prompt: str, system: str | None = None,
                 max_tokens: int = 512) -> str:
    """Dispatch to the configured engine: goldfish (Mizo/Paite) or Ollama."""
    if cfg.get("engine") == "goldfish":
        return await _goldfish(cfg, prompt, system=system, max_tokens=max_tokens)
    return await _ollama(cfg, prompt, system=system, max_tokens=max_tokens)


async def _ollama(cfg: dict, prompt: str, system: str | None = None,
                  max_tokens: int = 512) -> str:
    payload = {
        "model": _model_name(cfg),
        "prompt": prompt,
        "stream": False,
        "options": {
            "temperature": 0.3,
            "top_p": 0.85,
            "top_k": 40,
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


def _build_router(prefix: str, cfg: dict) -> APIRouter:
    router = APIRouter(prefix=f"/{prefix}", tags=[f"{prefix}-llm"])

    @router.post("/translate")
    async def translate(body: TranslateIn):
        key = f"{prefix}:tr:" + hashlib.sha256(
            f"{body.direction}|{body.text}".encode()).hexdigest()
        if cached := await _redis.get(key):
            return {"text": cached, "cached": True}
        prompt = (
            f"Translate to {cfg['name']}: {body.text}"
            if body.direction != "xx2en"
            else f"Translate to English: {body.text}"
        )
        out = await _infer(cfg, prompt)
        await _redis.set(key, out, ex=CACHE_TTL)
        return {"text": out, "cached": False}

    @router.post("/generate")
    async def generate(body: GenerateIn):
        system = body.system or cfg["system"]
        out = _validate(
            _strip_english_paragraphs(
                await _infer(cfg, body.prompt, system=system,
                             max_tokens=body.max_tokens)
            ),
            cfg,
        )
        return {"text": out}

    @router.get("/verse")
    async def verse(ref: str):
        """Exact Bible lookup from the bundled corpus — no LLM involved."""
        import bible_api  # always on sys.path via api.py
        try:
            text = bible_api.resolve(ref, lang=cfg["bible"])
        except Exception as exc:
            raise HTTPException(status_code=404, detail=str(exc)) from exc
        if not text:
            raise HTTPException(status_code=404, detail=f"Verse not found: {ref}")
        return {"text": text, "ref": ref, "lang": cfg["bible"]}

    return router


ROUTERS = [_build_router(prefix, cfg) for prefix, cfg in LANGS.items()]
