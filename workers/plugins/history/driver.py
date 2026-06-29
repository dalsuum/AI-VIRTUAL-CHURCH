"""Unified-history plugin driver — AI work for the conversation/history feature.

Two modes, both composed server-side by Laravel and pushed onto the `ai:history`
Redis list:

  - pastor_reply:  generate the AI Pastor's next reply for a Pastor Chat session.
  - title_summary: generate a short title + 2–5 sentence summary + auto-tags for any
                   session once it has a few turns (ChatGPT-style).

Results are POSTed back to the HMAC-signed /internal/history-callback endpoint.
Provider credentials are read from the environment, never from the job payload —
mirrors the Bible Study trust boundary. Reuses the LLM client + signed-post helper
from the bible_study driver so signing stays in one place.
"""

from __future__ import annotations

import json
import os
import re
import time

import requests

# Reuse the OpenRouter client + HMAC-signed POST helper (single source of truth).
from plugins.bible_study.driver import OpenRouterLLM, _signed_post

_HISTORY_WEBHOOK = os.getenv("HISTORY_CALLBACK_WEBHOOK_URL", "")
_DEFAULT_MODEL = os.getenv("HISTORY_LLM_MODEL", os.getenv("BIBLE_STUDY_LLM_MODEL", "anthropic/claude-sonnet-4-6"))

_LANG_NAME = {
    "en": "English", "my": "Burmese (Myanmar)", "td": "Tedim (Zolai)",
    "cnh": "Hakha Chin", "cfm": "Falam Chin", "lus": "Mizo",
    # World interface locales (mirror backend config/languages.php) so the
    # Pastor/title path responds in the user's chosen language.
    "fr": "French", "de": "German", "ja": "Japanese", "zh-CN": "Chinese (Simplified)",
    "hi": "Hindi", "ko": "Korean", "ar": "Arabic", "th": "Thai",
    "es": "Spanish", "ta": "Tamil",
}

# Low-resource languages with a native local model (FastAPI on aivc-tedim-api, :8001).
# These models are fine-tuned for native prose; we try them first and fall back to the
# cloud LLM when they 502 (degenerate output) or are unreachable. code -> URL path prefix.
_LOCAL_LLM_BASE = os.getenv("LOCAL_LLM_BASE_URL", "http://127.0.0.1:8001")
_LOCAL_LANG_PATH = {"td": "tedim", "cfm": "falam", "cnh": "hakha", "lus": "mizo"}


def _local_pastor_reply(language: str, system: str, messages: list) -> str | None:
    """Generate a pastoral reply from the native local model, or None to fall back.

    The local endpoints take a single {prompt, system} and self-validate, returning 502
    when their output is degenerate — in which case (or on any error) we return None so
    the caller uses the cloud LLM instead.
    """
    path = _LOCAL_LANG_PATH.get(language)
    if not path:
        return None
    convo = "\n".join(
        f"{'Pastor' if m['role'] == 'assistant' else 'Worshipper'}: {m['content']}"
        for m in messages if m.get("content")
    )
    prompt = f"{convo}\nPastor:" if convo else "Pastor:"

    # ms is the FULL local round-trip (request start → response or error) on EVERY exit
    # path, so success and all fallback reasons are latency-comparable and the dataset
    # isn't biased toward fast paths. Aggregate median/P95 ms by lang and by reason.
    started = time.monotonic()

    def _elapsed_ms() -> int:
        return int((time.monotonic() - started) * 1000)

    def _fallback(reason: str) -> None:
        # Structured reason aids production diagnostics: http_<code> (the service 502s on
        # failed Tedim-marker validation), empty (200 but no usable text), network (down).
        print(f"[history] pastor local fallback reason={reason} lang={language} path={path} ms={_elapsed_ms()}", flush=True)
        return None

    try:
        r = requests.post(
            f"{_LOCAL_LLM_BASE}/{path}/generate",
            json={"prompt": prompt, "system": system},
            # Short read timeout: on a CPU-only / memory-pressured box the local
            # model can stall well past a usable chat latency. Cap the wasted wait
            # so we fall back to the cloud LLM fast and the reply still feels live.
            timeout=15,
        )
        if r.status_code != 200:
            return _fallback(f"http_{r.status_code}")
        text = (r.json().get("text") or "").strip()
        if not text:
            return _fallback("empty")
        # Symmetric with the fallback line so the success-vs-fallback distribution is
        # fully countable from logs alone (no silent success path).
        print(f"[history] pastor local success lang={language} path={path} ms={_elapsed_ms()}", flush=True)
        return text
    except (requests.exceptions.RequestException, ValueError) as exc:
        print(f"[history] local {path} model error: {exc}", flush=True)
        return _fallback("network")


def _pastor_system(language: str, memory: list) -> str:
    lang = _LANG_NAME.get(language, "English")
    base = (
        "You are a warm, wise, biblically grounded AI pastor for a virtual church. "
        "You listen with compassion, respond pastorally, ground encouragement in "
        "Scripture (citing book chapter:verse), and never give medical, legal, or "
        "financial advice. Keep replies concise and caring. "
        # Understanding is never restricted to one language: comprehend the
        # worshipper whatever language they write in. The reply language is the
        # worshipper's selected language and is authoritative — do NOT mirror the
        # language their message happens to be written in. Only an explicit request
        # ("please answer in X") changes it, for the rest of the conversation.
        f"LANGUAGE LAW: Write EVERY sentence of your reply in {lang} ONLY, even when "
        "the worshipper writes to you in English or any other language. Understand "
        "whatever language they write in, but never switch your reply language just "
        f"because their message is in another language — keep replying in {lang}. The "
        "ONLY exception is when the worshipper explicitly asks you to answer in a "
        "different language; honour that request for the rest of the conversation."
    )
    if memory:
        recalls = "; ".join(
            f"{m.get('type')}: {m.get('title')} — {m.get('summary')}" for m in memory if m.get("summary")
        )
        if recalls:
            base += (
                "\n\nThe worshipper has prior sessions you MAY gently reference if "
                f"relevant (do not force it): {recalls}"
            )
    return base


def _detect_language(text: str, llm: OpenRouterLLM) -> str:
    """Classify the worshipper's first message into one supported code (default en).

    Burmese (my) is written in Myanmar script, so any Myanmar-range character is a
    decisive signal — and, conversely, Latin-only text can NOT be Burmese. Tedim,
    Hakha, Falam and Mizo are Chin languages all written in Latin script and look
    alike, so the LLM only has to disambiguate among the Latin set (and English).
    """
    sample = (text or "").strip()[:500]
    if not sample:
        return "en"
    # Decisive: Myanmar script => Burmese.
    if any("က" <= c <= "႟" for c in sample):
        return "my"
    # Decisive: Tedim/Zolai-specific tokens that Mizo, English and the other Chin
    # languages do not use. The cloud LLM tends to over-collapse the closely related
    # Latin-script Chin languages onto "Mizo", so a lexical short-circuit keeps Tedim
    # from being misrouted (e.g. "Biakinn pai hoih hia?" => td, not lus).
    if re.search(
        r"\b(hia|hiam|hoih|pasian|topa|zeisu|tuni|nuntakna|lametna|lungdamna|"
        r"hehpihna|kong|hong|siam|biakinn|pai)\b",
        sample.lower(),
    ):
        return "td"
    # Latin script => never Burmese; restrict the candidate set accordingly.
    latin_codes = [c for c in _LANG_NAME if c != "my"]  # en, td, cnh, cfm, lus
    out, _ = llm.complete(
        system=("Identify the language of this message, which is written in Latin script "
                "(so it is NOT Burmese). Reply with ONLY one code and nothing else from: "
                f"{', '.join(latin_codes)}. Tedim/Zolai (td), Hakha (cnh), Falam (cfm) and "
                "Mizo (lus) are closely related Chin languages — pick the closest. Use 'en' "
                "only for clearly English text; if it is non-English but you are unsure which "
                "Chin language, prefer 'td'."),
        messages=[{"role": "user", "content": sample}],
        temperature=0.0, max_tokens=4, role="detect",
    )
    code = (out or "").strip().lower().split(maxsplit=1)[0] if (out or "").strip() else "en"
    return code if code in latin_codes else "en"


def _run_pastor_reply(job: dict, llm: OpenRouterLLM) -> None:
    messages = [
        {"role": "assistant" if t.get("role") == "assistant" else "user",
         "content": t.get("content", "")}
        for t in (job.get("turns") or [])
    ]
    # 'auto' (or any unknown code) → detect from the first worshipper turn, then lock it
    # in via the callback so follow-up turns arrive with the resolved code.
    language = (job.get("language") or "en").strip().lower()
    detected = None
    if language not in _LANG_NAME:
        first_user = next((m["content"] for m in messages if m["role"] == "user"), "")
        language = detected = _detect_language(first_user, llm)

    system = _pastor_system(language, job.get("memory") or [])

    # Low-resource languages: prefer the native local model, fall back to the cloud LLM.
    reply = _local_pastor_reply(language, system, messages)
    token_usage = 0
    if reply is None:
        reply, usage = llm.complete(
            system=system, messages=messages, temperature=0.7, max_tokens=700, role="pastor"
        )
        token_usage = int(usage.get("total_tokens", 0) or 0)

    _signed_post(_HISTORY_WEBHOOK, {
        "mode": "pastor_reply",
        "session_id": job["session_id"],
        "reply": reply.strip(),
        "token_usage": token_usage,
        "detected_language": detected,
    })


def _run_vocab_generate(job: dict, llm: OpenRouterLLM) -> None:
    """Render an existing dictionary concept into a full learner entry in one language.

    The seed concept comes from the curated `vocabularies` row (English + Zolai gloss);
    the model produces the per-language payload and we cache it server-side. Same
    authoritative LANGUAGE LAW as Pastor Chat so the entry never drifts into English.
    """
    lang = _LANG_NAME.get(job.get("language", "en"), "English")
    concept = (job.get("concept") or "").strip()
    system = (
        "You are a bilingual lexicographer building a language-learning dictionary "
        f"entry. LANGUAGE LAW: first TRANSLATE the concept into {lang}, then write "
        f"EVERY field's value in {lang} ONLY. The 'word' field MUST be the {lang} "
        f"word for the concept — never English and never any other language. Do not "
        "use English anywhere except inside the optional bible_verse 'ref' (e.g. "
        "'John 3:16'). Return STRICT JSON with keys: \"word\", \"pronunciation\", "
        '"part_of_speech", "meaning", "definition", "example", "synonyms" (array), '
        '"antonyms" (array), "related" (array), "bible_verse" (object {ref, text} or '
        'null), "difficulty". The "difficulty" value is the ONE EXCEPTION to the '
        'language law: it MUST be exactly one of the English tokens "beginner", '
        '"intermediate" or "advanced". JSON only, no prose outside it.'
    )
    # The concept (in English) is the sole seed; we deliberately do NOT pass the Zolai
    # gloss — feeding a Chin word biases the model into echoing it for languages it is
    # less fluent in, instead of translating the concept (observed for he/ta/hi/th).
    prompt = f"Concept to render into {lang}: {concept}"
    text, usage = llm.complete(
        system=system,
        messages=[{"role": "user", "content": prompt}],
        # Token-dense scripts (Burmese, Thai, …) need headroom or the JSON truncates
        # mid-field and fails to parse — 700 was too low (observed for my).
        temperature=0.4, max_tokens=1200, role="vocab",
    )

    payload, word, difficulty = None, None, None
    try:
        start, end = text.find("{"), text.rfind("}")
        parsed = json.loads(text[start:end + 1]) if start >= 0 and end > start else {}
        word = (parsed.get("word") or "").strip() or None
        difficulty = parsed.get("difficulty") if parsed.get("difficulty") in (
            "beginner", "intermediate", "advanced") else None
        if word:
            payload = parsed
    except (ValueError, TypeError) as exc:
        print(f"[history] vocab_generate parse failed: {exc}", flush=True)

    _signed_post(_HISTORY_WEBHOOK, {
        "mode": "vocab_entry",
        "vocabulary_id": job.get("vocabulary_id"),
        "language": job.get("language"),
        "word": word,
        "difficulty": difficulty,
        "payload": payload,
        "token_usage": int((usage or {}).get("total_tokens", 0) or 0),
    })


def _run_vocab_explain(job: dict, llm: OpenRouterLLM) -> None:
    """A warm teaching explanation of a concept — meaning, usage, grammar, pronunciation,
    example sentences — entirely in the learner's language. Cached per (concept, language)."""
    lang = _LANG_NAME.get(job.get("language", "en"), "English")
    concept = (job.get("concept") or "").strip()
    system = (
        "You are a friendly language teacher for a Christian learner. LANGUAGE LAW: write "
        f"your ENTIRE explanation in {lang} ONLY, even if the concept is given in English. "
        f"Explain the {lang} word for the concept: its meaning, how and when it is used, any "
        "grammar notes, how to pronounce it, and 1-2 natural example sentences. Keep it warm, "
        "concise (under ~180 words), and plain text (no JSON, no markdown headings)."
    )
    text, usage = llm.complete(
        system=system,
        messages=[{"role": "user", "content": f"Concept to explain in {lang}: {concept}"}],
        temperature=0.5, max_tokens=900, role="vocab_explain",
    )
    _signed_post(_HISTORY_WEBHOOK, {
        "mode": "vocab_explanation",
        "vocabulary_id": job.get("vocabulary_id"),
        "language": job.get("language"),
        "explanation": (text or "").strip() or None,
        "token_usage": int((usage or {}).get("total_tokens", 0) or 0),
    })


def _run_title_summary(job: dict, llm: OpenRouterLLM) -> None:
    vocab = job.get("tag_vocab") or []
    convo = "\n".join(
        f"{t.get('sender', t.get('role', 'user'))}: {t.get('content', '')}"
        for t in (job.get("turns") or [])
    )
    system = (
        "You summarize a spiritual conversation. Return STRICT JSON with keys: "
        '"title" (max 6 words, no quotes), "summary" (2-5 sentences), and "tags" '
        f"(array, choose 1-4 ONLY from this list: {vocab}). No prose outside the JSON."
    )
    text, _ = llm.complete(
        system=system,
        messages=[{"role": "user", "content": convo[:6000]}],
        temperature=0.3, max_tokens=400, role="summary",
    )

    title, summary, tags = None, None, []
    try:
        start, end = text.find("{"), text.rfind("}")
        parsed = json.loads(text[start:end + 1]) if start >= 0 and end > start else {}
        title = (parsed.get("title") or "").strip()[:200] or None
        summary = (parsed.get("summary") or "").strip() or None
        tags = [t for t in (parsed.get("tags") or []) if t in vocab][:4]
    except (ValueError, TypeError) as exc:  # malformed model output — degrade gracefully
        print(f"[history] title_summary parse failed: {exc}", flush=True)

    _signed_post(_HISTORY_WEBHOOK, {
        "mode": "title_summary",
        "session_id": job["session_id"],
        "title": title,
        "summary": summary,
        "tags": tags,
    })


def _run_journal(job: dict, llm: OpenRouterLLM) -> None:
    lang = _LANG_NAME.get(job.get("language", "en"), "English")
    convo = "\n".join(
        f"{t.get('sender', 'user')}: {t.get('content', '')}" for t in (job.get("turns") or [])
    )
    context = (job.get("summary") or "") + "\n\n" + convo
    system = (
        "You are a gentle spiritual journal-keeper. From the session below, write a "
        "short reflective journal entry FOR the worshipper, in second person ('Today "
        f"you…'). Reply in {lang}. Return STRICT JSON with keys: \"title\" (max 8 "
        "words), \"scripture_ref\" (one primary reference like 'Psalm 23' or empty), "
        "\"insight\" (2-3 sentences on the main spiritual insight), \"prayer\" (a short "
        "1-3 sentence prayer), \"reflection\" (1-2 reflective questions). JSON only."
    )
    text, _ = llm.complete(
        system=system,
        messages=[{"role": "user", "content": context[:6000]}],
        temperature=0.5, max_tokens=600, role="journal",
    )

    fields = {"title": None, "scripture_ref": None, "insight": None, "prayer": None, "reflection": None}
    try:
        start, end = text.find("{"), text.rfind("}")
        parsed = json.loads(text[start:end + 1]) if start >= 0 and end > start else {}
        for k in fields:
            v = parsed.get(k)
            fields[k] = v.strip() if isinstance(v, str) and v.strip() else None
    except (ValueError, TypeError) as exc:
        print(f"[history] journal parse failed: {exc}", flush=True)

    _signed_post(_HISTORY_WEBHOOK, {
        "mode": "journal",
        "journal_entry_id": job["journal_entry_id"],
        **fields,
    })


def run(job: dict) -> None:
    """Celery entry point for the unified-history queue."""
    session_id = job.get("session_id") or job.get("journal_entry_id")
    mode = job.get("mode", "")
    model = (job.get("provider") or {}).get("model") or _DEFAULT_MODEL
    llm = OpenRouterLLM(model)

    print(f"[history] {session_id} mode={mode} model={model}", flush=True)
    if mode == "pastor_reply":
        _run_pastor_reply(job, llm)
    elif mode == "title_summary":
        _run_title_summary(job, llm)
    elif mode == "journal":
        _run_journal(job, llm)
    elif mode == "vocab_generate":
        _run_vocab_generate(job, llm)
    elif mode == "vocab_explain":
        _run_vocab_explain(job, llm)
    else:
        print(f"[history] unknown mode {mode!r}, ignoring", flush=True)
