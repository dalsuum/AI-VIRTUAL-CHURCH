"""Persona Engine (Phase 6) — weighted, deterministic agent selection.

Personas are passed in already scoped to (module, language) and enabled — Laravel
lazy-loads only that slice (never all 29). This module selects N agents (2–7) with
weighted sampling, picks/promotes a moderator, and orders speakers so a contrasting
"challenger" surfaces early. Seeding is server-side only: hash(session_id, round).
"""

from __future__ import annotations

import hashlib
import random


def _seed(session_id: int, round_no: int) -> int:
    raw = f"{session_id}:{round_no}".encode()
    return int.from_bytes(hashlib.sha256(raw).digest()[:8], "big")


def _weighted_sample(items: list[dict], k: int, rng: random.Random) -> list[dict]:
    """Sample k items without replacement, probability biased by 'weight'."""
    pool = list(items)
    chosen: list[dict] = []
    while pool and len(chosen) < k:
        weights = [max(1, int(p.get("weight", 1))) for p in pool]
        total = sum(weights)
        r = rng.uniform(0, total)
        upto = 0.0
        for i, w in enumerate(weights):
            upto += w
            if r <= upto:
                chosen.append(pool.pop(i))
                break
    return chosen


def select(personas: list[dict], agent_count: int, session_id: int, round_no: int = 1) -> dict:
    """Return {'moderator': persona|None, 'pastors': [persona,...ordered]}.

    `agent_count` is the number of PASTORS in the panel (the moderator is separate
    when one exists). Caller is responsible for clamping agent_count to 2–7.
    """
    rng = random.Random(_seed(session_id, round_no))

    enabled = [p for p in personas if p.get("enabled", True)]
    moderators = [p for p in enabled if p.get("is_moderator")]
    pastors_pool = [p for p in enabled if not p.get("is_moderator")]

    moderator = None
    if moderators:
        moderator = max(moderators, key=lambda p: p.get("weight", 0))
    elif pastors_pool:
        # Promote the highest-weight pastor to moderator for this round.
        moderator = max(pastors_pool, key=lambda p: p.get("weight", 0))
        pastors_pool = [p for p in pastors_pool if p is not moderator]

    n = max(0, min(agent_count, len(pastors_pool)))
    selected = _weighted_sample(pastors_pool, n, rng)

    # Order by descending weight, then move the lowest-weight "challenger" into
    # slot 2 so respectful disagreement surfaces early rather than last.
    selected.sort(key=lambda p: p.get("weight", 0), reverse=True)
    if len(selected) >= 3:
        challenger = min(selected, key=lambda p: p.get("weight", 0))
        selected.remove(challenger)
        selected.insert(1, challenger)

    return {"moderator": moderator, "pastors": selected}


def token_budget(persona: dict, base_max_tokens: int) -> int:
    """Scale a pastor's per-turn token budget by weight (high weight may expand)."""
    weight = max(1, min(100, int(persona.get("weight", 50))))
    factor = 0.7 + (weight / 100.0) * 0.6  # 0.7x … 1.3x
    return max(120, int(base_max_tokens * factor))
