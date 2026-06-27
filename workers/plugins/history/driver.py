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

# Reuse the OpenRouter client + HMAC-signed POST helper (single source of truth).
from plugins.bible_study.driver import OpenRouterLLM, _signed_post

_HISTORY_WEBHOOK = os.getenv("HISTORY_CALLBACK_WEBHOOK_URL", "")
_DEFAULT_MODEL = os.getenv("HISTORY_LLM_MODEL", os.getenv("BIBLE_STUDY_LLM_MODEL", "anthropic/claude-sonnet-4-6"))

_LANG_NAME = {
    "en": "English", "my": "Burmese (Myanmar)", "td": "Tedim (Zolai)",
    "cnh": "Hakha Chin", "cfm": "Falam Chin", "lus": "Mizo",
}


def _pastor_system(language: str, memory: list) -> str:
    lang = _LANG_NAME.get(language, "English")
    base = (
        "You are a warm, wise, biblically grounded AI pastor for a virtual church. "
        "You listen with compassion, respond pastorally, ground encouragement in "
        "Scripture (citing book chapter:verse), and never give medical, legal, or "
        f"financial advice. Reply ONLY in {lang}. Keep replies concise and caring."
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
    """Classify the worshipper's first message into one supported code (default en)."""
    sample = (text or "").strip()[:500]
    if not sample:
        return "en"
    out, _ = llm.complete(
        system=("Identify the language of the worshipper's message. Reply with ONLY one "
                f"code from this list and nothing else: {', '.join(_LANG_NAME)}. "
                "If unsure, reply 'en'."),
        messages=[{"role": "user", "content": sample}],
        temperature=0.0, max_tokens=4, role="detect",
    )
    code = (out or "").strip().lower().split(maxsplit=1)[0] if (out or "").strip() else "en"
    return code if code in _LANG_NAME else "en"


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
    text, usage = llm.complete(
        system=system, messages=messages, temperature=0.7, max_tokens=700, role="pastor"
    )
    _signed_post(_HISTORY_WEBHOOK, {
        "mode": "pastor_reply",
        "session_id": job["session_id"],
        "reply": text.strip(),
        "token_usage": int(usage.get("total_tokens", 0) or 0),
        "detected_language": detected,
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
    else:
        print(f"[history] unknown mode {mode!r}, ignoring", flush=True)
