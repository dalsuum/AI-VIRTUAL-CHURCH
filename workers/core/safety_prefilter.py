"""Two-stage safety — stage 1: cheap PRE-filter (Phase 7).

Runs on the worshipper's input BEFORE any orchestration/LLM call. Catches obvious
prompt-injection / jailbreak attempts and clearly disallowed content fast, denying
by default on a confident hit. The authoritative POST-filter remains classifier.review
on every generated turn (non-bypassable). This pre-filter only reduces wasted spend
and blocks the loudest attacks early; it is intentionally conservative to avoid
false positives on legitimate questions.
"""

from __future__ import annotations

import re

# Prompt-injection / jailbreak signatures (case-insensitive, substring or regex).
_INJECTION_PATTERNS = (
    r"ignore (all |the )?(previous|above|prior) (instructions|prompts?|messages?)",
    r"disregard (all |the )?(previous|above|prior)",
    r"reveal (your )?(system )?(prompt|instructions)",
    r"what (is|are) your (system )?(prompt|instructions)",
    r"print (your )?(system )?(prompt|instructions)",
    r"you are (now|actually) (a|an|not)",
    r"pretend (to be|you are)",
    r"developer mode",
    r"jailbreak",
    r"override (the )?(moderator|rules|system)",
    r"reveal (the )?(real|true) (name|identity|pastor)",
    r"who inspired you",
    r"\bDAN\b mode",
)

_COMPILED = [re.compile(p, re.IGNORECASE) for p in _INJECTION_PATTERNS]

# Hard length guard — absurdly long input is rejected before tokenization cost.
_MAX_INPUT_CHARS = 4000


def check(text: str) -> tuple[bool, str]:
    """Return (ok, reason). ok=False means block before orchestration."""
    if text is None:
        return False, "empty input"
    stripped = text.strip()
    if not stripped:
        return False, "empty input"
    if len(stripped) > _MAX_INPUT_CHARS:
        return False, "input too long"

    for rx in _COMPILED:
        if rx.search(stripped):
            return False, "possible prompt-injection attempt"

    return True, ""
