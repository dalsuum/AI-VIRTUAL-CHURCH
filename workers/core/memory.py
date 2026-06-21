"""Memory Engine (Phase 6) — window strategy for v1.

Owner-scoping is enforced upstream in Laravel (queries filter user_id XOR
guest_session_id); the worker only receives memory already scoped to this session/
owner. v1 implements the 'window' strategy (recent turns) and accepts prior
moderator summaries for context. Only moderator-synthesized summaries are eligible
for long-term ingestion (decided by Laravel), keeping pastor noise out of recall.
"""

from __future__ import annotations


def window_context(prior_turns: list[dict], max_turns: int = 8) -> list[dict]:
    """Return the last `max_turns` prior turns as untrusted context entries
    [{name, content}], oldest→newest. Used to give pastors the conversation so far."""
    trimmed = [t for t in (prior_turns or []) if t.get("content")][-max_turns:]
    return [{"name": t.get("name", "a pastor"), "content": t.get("content", "")} for t in trimmed]


def summary_context(summaries: list[str], max_items: int = 3) -> str:
    """Condense prior round summaries into a short trusted-context string."""
    items = [s.strip() for s in (summaries or []) if s and s.strip()][-max_items:]
    return "\n".join(f"- {s}" for s in items)
