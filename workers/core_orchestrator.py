"""Core Orchestrator (Phase 6) — module-agnostic discussion loop.

Drives one round: FRAME (moderator) -> DELIBERATE (weighted pastors, ordered) ->
SYNTHESIZE (moderator). Reuses the Core services: persona_engine (selection),
prompt_engine (injection-resistant composition), scripture/rag (canonical verses),
and the safety post-filter. All side-effecting collaborators are injected (LLM
client, EventBus, turn sink, review fn) so the round logic is unit-testable without
Redis, Celery, or a provider.

Scripture is resolved DETERMINISTICALLY here (the model cites references; the engine
resolves them) — no arbitrary tool-calling is exposed to the model.
"""

from __future__ import annotations

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from core import persona_engine, prompt_engine, rag        # noqa: E402
from core.prompt_engine import TrustedContext              # noqa: E402

# How many words per streamed token.delta chunk (live-feel without provider streaming).
_CHUNK_WORDS = 6


def _default_review(text: str):
    """Authoritative post-filter. Uses workers/classifier.review when available."""
    try:
        import classifier  # noqa: E402
        return classifier.review(text)
    except Exception:
        return True, ""


def _stream_text(bus, *, turn, persona_id, content):
    words = content.split()
    for i in range(0, len(words), _CHUNK_WORDS):
        bus.publish("token.delta", turn=turn, persona_id=persona_id,
                    text=" ".join(words[i:i + _CHUNK_WORDS]) + " ")


class FakeLLM:
    """Test double: returns scripted text per role, records calls."""

    def __init__(self, scripts: dict | None = None):
        self.scripts = scripts or {}
        self.calls: list[dict] = []

    def complete(self, *, system, messages, temperature, max_tokens, role=None):
        self.calls.append({"system": system, "messages": messages, "role": role,
                           "max_tokens": max_tokens})
        text = self.scripts.get(role, f"[{role}] response")
        return text, {"prompt_tokens": 10, "completion_tokens": 20}


def run_round(*, job: dict, llm, bus, turn_sink=None, review_fn=None, base_turn: int = 0) -> list[dict]:
    """Run one discussion round. Returns the list of persisted turn dicts."""
    review_fn = review_fn or _default_review
    sink = turn_sink or (lambda t: None)

    session_id   = job["session_id"]
    language_name = job.get("language_name", "English")
    translation  = job.get("translation", "en")
    question     = job.get("question", "")
    personas     = job.get("personas", [])
    templates    = job.get("templates", {})
    agent_count  = int(job.get("agent_count", 4))
    round_no     = int(job.get("round_no", 1))

    roster = persona_engine.select(personas, agent_count, session_id, round_no)
    moderator = roster["moderator"]
    pastors   = roster["pastors"]

    turn = base_turn
    turns: list[dict] = []
    round_verses: dict[str, dict] = {}   # canonical_id -> verse dict (dedupe cards)

    def _emit_turn(role, persona, content, extra_verses_text="", usage=None):
        nonlocal turn
        turn += 1
        ok, reason = review_fn(content)
        pid = (persona or {}).get("id")
        name = (persona or {}).get("display_name", "Moderator")
        ptok = int((usage or {}).get("prompt_tokens", 0) or 0)
        ctok = int((usage or {}).get("completion_tokens", 0) or 0)
        if not ok:
            bus.publish("safety.blocked", turn=turn, persona_id=pid, reason=reason)
            rec = {"turn": turn, "role": role, "persona_id": pid,
                   "display_name": name, "content": "", "scripture_refs": [],
                   "safety_flag": True, "prompt_tokens": ptok, "completion_tokens": ctok}
            sink(rec)
            turns.append(rec)
            return rec

        bus.publish("agent.started", turn=turn, persona_id=pid, display_name=name, role=role)
        _stream_text(bus, turn=turn, persona_id=pid, content=content)

        # Deterministic scripture detection for inline cards.
        new_cards = rag.scripture_in_text((extra_verses_text + " " + content).strip(), translation)
        refs_for_turn = []
        for v in new_cards:
            refs_for_turn.append(v["ref"])
            if v["canonical_id"] not in round_verses:
                round_verses[v["canonical_id"]] = v
                bus.publish("verse.card", turn=turn, persona_id=pid,
                            ref=v["ref"], translation=v["translation"])

        rec = {"turn": turn, "role": role, "persona_id": pid, "display_name": name,
               "content": content, "scripture_refs": refs_for_turn, "safety_flag": False,
               "prompt_tokens": ptok, "completion_tokens": ctok}
        bus.publish("turn.complete", turn=turn, persona_id=pid, message_id=None,
                    prompt_tokens=ptok, completion_tokens=ctok)
        sink(rec)
        turns.append(rec)
        return rec

    def _call(role, persona, trusted, untrusted, user_content, tmpl_key):
        tmpl = templates.get(tmpl_key, {})
        cp = prompt_engine.compose(
            language_name=language_name,
            role_template_body=tmpl.get("body", ""),
            persona_system_prompt=(persona or {}).get("system_prompt", ""),
            trusted=trusted,
            untrusted_turns=untrusted,
            user_content=user_content,
            temperature=float(tmpl.get("temperature", 0.7)),
            max_tokens=persona_engine.token_budget(persona or {}, int(tmpl.get("max_tokens", 700))),
        )
        text, usage = llm.complete(system=cp.system, messages=cp.messages,
                                   temperature=cp.temperature, max_tokens=cp.max_tokens,
                                   role=role)
        return (text or "").strip(), (usage or {})

    # ── FRAME ────────────────────────────────────────────────────────────────
    bus.publish("state.changed", state="discussing")
    # Resolve question references for the frame's trusted brief. Do NOT pre-seed
    # round_verses here — _emit_turn() detects + emits the cards and fills the
    # dedup set, so pre-seeding would suppress the frame's own verse cards.
    frame_verses = rag.scripture_in_text(question, translation)
    frame_text, frame_usage = _call(
        "frame", moderator,
        TrustedContext(verses=tuple(frame_verses)),
        untrusted=[], user_content=question, tmpl_key="frame",
    )
    _emit_turn("moderator", moderator, frame_text, extra_verses_text=question, usage=frame_usage)

    # ── DELIBERATE ────────────────────────────────────────────────────────────
    for pastor in pastors:
        prior = [{"name": t["display_name"], "content": t["content"]}
                 for t in turns if t["role"] in ("pastor", "moderator") and t["content"]]
        trusted = TrustedContext(
            moderator_frame=frame_text,
            assigned_angle=pastor.get("tradition_tag", ""),
            verses=tuple(round_verses.values()),
        )
        text, usage = _call("pastor", pastor, trusted, untrusted=prior,
                            user_content=question, tmpl_key="pastor")
        _emit_turn("pastor", pastor, text, usage=usage)

    # ── SYNTHESIZE ────────────────────────────────────────────────────────────
    pastor_turns = [{"name": t["display_name"], "content": t["content"]}
                    for t in turns if t["role"] == "pastor" and t["content"]]
    synth, synth_usage = _call(
        "synthesis", moderator,
        TrustedContext(moderator_frame=frame_text, verses=tuple(round_verses.values())),
        untrusted=pastor_turns, user_content=question, tmpl_key="synthesis",
    )
    _emit_turn("synthesis", moderator, synth, usage=synth_usage)

    bus.publish("round.complete", round=round_no)
    return turns


def run_summary(*, job: dict, llm, review_fn=None) -> dict:
    """Generate the end-of-discussion summary. The whole transcript is fed as
    UNTRUSTED context; the moderator template asks for STRICT JSON sections. Returns
    the parsed dict (best-effort) so the caller can persist it. Never invents
    scripture (cited verses are resolved separately for cards)."""
    import json
    import re

    review_fn = review_fn or _default_review
    language_name = job.get("language_name", "English")
    templates = job.get("templates", {})
    personas = job.get("personas", [])
    moderator = next((p for p in personas if p.get("is_moderator")), (personas or [{}])[0])
    turns = job.get("turns", [])

    tmpl = templates.get("summary", {})
    cp = prompt_engine.compose(
        language_name=language_name,
        role_template_body=tmpl.get("body", ""),
        persona_system_prompt=(moderator or {}).get("system_prompt", ""),
        trusted=TrustedContext(),
        untrusted_turns=[{"name": t.get("role", "pastor"), "content": t.get("content", "")} for t in turns],
        user_content="",
        temperature=float(tmpl.get("temperature", 0.4)),
        max_tokens=int(tmpl.get("max_tokens", 2500)),
    )
    text, _usage = llm.complete(system=cp.system, messages=cp.messages,
                                temperature=cp.temperature, max_tokens=cp.max_tokens,
                                role="summary")
    text = (text or "").strip()

    ok, _reason = review_fn(text)
    if not ok:
        return {"lessons": [], "blocked": True}

    # Best-effort JSON extraction (model may wrap in code fences).
    raw = text.strip().removeprefix("```json").removeprefix("```").removesuffix("```").strip()
    try:
        parsed = json.loads(raw)
        if isinstance(parsed, dict):
            return parsed
    except json.JSONDecodeError:
        m = re.search(r"\{.*\}", raw, re.DOTALL)
        if m:
            try:
                return json.loads(m.group(0))
            except json.JSONDecodeError:
                pass

    # Parse failed (commonly: output truncated by max_tokens mid-JSON, frequent with
    # token-heavy languages). Salvage whatever complete fields exist rather than
    # dumping the raw blob to the user.
    salvaged = _salvage_summary(raw)
    if salvaged:
        return salvaged

    # Nothing salvageable → keep cleaned prose (no code fence) as a single lesson.
    return {"lessons": [raw]}


def _salvage_summary(raw: str) -> dict:
    """Best-effort extraction of summary fields from malformed/truncated JSON."""
    import re as _re

    out: dict = {}
    array_keys = ["key_verses", "lessons", "action_points", "reflection_questions", "study_plan"]
    for key in array_keys:
        m = _re.search(rf'"{key}"\s*:\s*\[(.*?)\]', raw, _re.DOTALL)
        if not m:
            continue
        items = _re.findall(r'"((?:[^"\\]|\\.)*)"', m.group(1))
        items = [i.replace('\\"', '"').strip() for i in items if i.strip()]
        if items:
            out[key] = items

    pm = _re.search(r'"prayer"\s*:\s*"((?:[^"\\]|\\.)*)"', raw, _re.DOTALL)
    if pm:
        out["prayer"] = pm.group(1).replace('\\"', '"').strip()

    return out
