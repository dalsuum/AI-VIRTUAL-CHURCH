"""
Post-generation guardrail. Runs over generated text before it is sent to Laravel.
Lightweight deny-list pass; in production, back this with a small classifier model.
Returns (ok, reason).
"""

from __future__ import annotations

DENY = ["kill yourself", "you should die", "worthless", "damned to hell"]


def review(text: str) -> tuple[bool, str | None]:
    low = text.lower()
    for phrase in DENY:
        if phrase in low:
            return False, f"blocked phrase: {phrase}"
    return True, None
