"""Tests for the Core Orchestrator + Persona Engine (Phase 6), using fakes."""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import core_orchestrator as orch          # noqa: E402
from core import persona_engine            # noqa: E402
from core.events import RecordingBus       # noqa: E402


def _personas():
    return [
        {"id": 1, "display_name": "Mod", "system_prompt": "mod", "weight": 60, "is_moderator": True, "tradition_tag": "moderator-synthesis"},
        {"id": 2, "display_name": "Grace", "system_prompt": "g", "weight": 80, "is_moderator": False, "tradition_tag": "grace"},
        {"id": 3, "display_name": "Matthew", "system_prompt": "m", "weight": 70, "is_moderator": False, "tradition_tag": "expository"},
        {"id": 4, "display_name": "Daniel", "system_prompt": "d", "weight": 60, "is_moderator": False, "tradition_tag": "pastoral"},
        {"id": 5, "display_name": "Hope", "system_prompt": "h", "weight": 40, "is_moderator": False, "tradition_tag": "youth"},
    ]


def _templates():
    return {
        "frame":     {"body": "frame", "temperature": 0.6, "max_tokens": 400},
        "pastor":    {"body": "pastor", "temperature": 0.7, "max_tokens": 600},
        "synthesis": {"body": "synth", "temperature": 0.5, "max_tokens": 400},
    }


def _job(agent_count=3):
    return {
        "session_id": 42, "language_name": "English", "translation": "kjv",
        "question": "What does John 3:16 teach about grace?",
        "personas": _personas(), "templates": _templates(),
        "agent_count": agent_count, "round_no": 1,
    }


# ---- persona engine -------------------------------------------------------

def test_selection_picks_moderator_and_n_pastors():
    r = persona_engine.select(_personas(), 3, session_id=42)
    assert r["moderator"]["is_moderator"] is True
    assert len(r["pastors"]) == 3
    assert all(not p["is_moderator"] for p in r["pastors"])


def test_selection_is_deterministic():
    a = persona_engine.select(_personas(), 3, 42, 1)
    b = persona_engine.select(_personas(), 3, 42, 1)
    assert [p["id"] for p in a["pastors"]] == [p["id"] for p in b["pastors"]]


def test_challenger_inserted_at_slot_two():
    r = persona_engine.select(_personas(), 4, 42)
    weights = [p["weight"] for p in r["pastors"]]
    # slot 2 (index 1) is the lowest-weight challenger among the selected.
    assert weights[1] == min(weights)


def test_promotes_moderator_when_none_flagged():
    personas = [p for p in _personas() if not p["is_moderator"]]
    r = persona_engine.select(personas, 2, 42)
    assert r["moderator"] is not None and r["moderator"]["is_moderator"] is False


def test_token_budget_scales_with_weight():
    hi = persona_engine.token_budget({"weight": 100}, 700)
    lo = persona_engine.token_budget({"weight": 0}, 700)
    assert hi > lo


# ---- orchestrator round ---------------------------------------------------

def test_round_structure_frame_pastors_synthesis():
    bus = RecordingBus(session_id=42)
    llm = orch.FakeLLM({"frame": "Let's explore John 3:16.",
                        "pastor": "Grace abounds in John 3:16.",
                        "synthesis": "We agree grace is central."})
    turns = orch.run_round(job=_job(3), llm=llm, bus=bus,
                           review_fn=lambda t: (True, ""))
    roles = [t["role"] for t in turns]
    assert roles[0] == "moderator"           # frame
    assert roles[-1] == "synthesis"          # synthesis
    assert roles.count("pastor") == 3
    # round.complete is the final event
    assert bus.events[-1]["event"] == "round.complete"


def test_seq_is_monotonic():
    bus = RecordingBus(session_id=42)
    llm = orch.FakeLLM()
    orch.run_round(job=_job(2), llm=llm, bus=bus, review_fn=lambda t: (True, ""))
    seqs = [e["seq"] for e in bus.events]
    assert seqs == sorted(seqs) and len(set(seqs)) == len(seqs)


def test_verse_card_emitted_for_detected_reference():
    bus = RecordingBus(session_id=42)
    llm = orch.FakeLLM({"frame": "Consider John 3:16 carefully.",
                        "pastor": "Yes.", "synthesis": "Done."})
    orch.run_round(job=_job(2), llm=llm, bus=bus, review_fn=lambda t: (True, ""))
    cards = [e for e in bus.events if e["event"] == "verse.card"]
    assert any(c["ref"] == "John 3:16" for c in cards)


def test_safety_block_suppresses_content():
    bus = RecordingBus(session_id=42)
    llm = orch.FakeLLM({"frame": "BLOCK ME", "pastor": "ok", "synthesis": "ok"})
    turns = orch.run_round(job=_job(2), llm=llm, bus=bus,
                           review_fn=lambda t: (False, "blocked") if "BLOCK" in t else (True, ""))
    blocked = [t for t in turns if t["safety_flag"]]
    assert blocked and blocked[0]["content"] == ""
    assert any(e["event"] == "safety.blocked" for e in bus.events)


def test_usage_tokens_threaded_to_turns():
    # Token usage from the LLM must reach each persisted turn (feeds ai_usage_ledger).
    bus = RecordingBus(session_id=42)
    llm = orch.FakeLLM()  # FakeLLM reports prompt=10, completion=20 per call
    turns = orch.run_round(job=_job(2), llm=llm, bus=bus, review_fn=lambda t: (True, ""))
    assert turns and all(t["prompt_tokens"] == 10 and t["completion_tokens"] == 20 for t in turns)


def test_untrusted_pastor_output_not_in_system_prompt():
    # A pastor turn must reach later turns only as untrusted (user) content.
    bus = RecordingBus(session_id=42)
    llm = orch.FakeLLM({"frame": "frame", "pastor": "INJECT-MARKER", "synthesis": "s"})
    orch.run_round(job=_job(3), llm=llm, bus=bus, review_fn=lambda t: (True, ""))
    # Inspect the synthesis call: marker should be in user messages, never system.
    synth_calls = [c for c in llm.calls if c["role"] == "synthesis"]
    assert synth_calls
    sysmsg = synth_calls[0]["system"]
    usermsg = " ".join(m["content"] for m in synth_calls[0]["messages"])
    assert "INJECT-MARKER" not in sysmsg
    assert "INJECT-MARKER" in usermsg


if __name__ == "__main__":
    import traceback

    fns = [v for k, v in sorted(globals().items()) if k.startswith("test_") and callable(v)]
    passed = 0
    for fn in fns:
        try:
            fn()
            print(f"PASS {fn.__name__}")
            passed += 1
        except Exception:
            print(f"FAIL {fn.__name__}")
            traceback.print_exc()
    print(f"\n{passed}/{len(fns)} passed")
    sys.exit(0 if passed == len(fns) else 1)
