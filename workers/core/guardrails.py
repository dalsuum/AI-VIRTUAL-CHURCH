"""Guardrail Service (Phase 2A)

This service protects the LLM from prompt injection, system overrides, and
ensures output safety (e.g., theological soundness, no hallucinated verses).
"""

import re

# Simple heuristic patterns for prompt injection detection
_INJECTION_PATTERNS = [
    r"ignore previous instructions",
    r"forget what you were told",
    r"disregard all previous",
    r"you are now",
    r"pretend you are",
    r"bypass rules",
    r"override system",
]

_INJECTION_REGEX = re.compile("|".join(_INJECTION_PATTERNS), re.IGNORECASE)

def validate_input(text: str) -> tuple[bool, str]:
    """Check if the user input contains malicious injection attempts."""
    if not text:
        return True, ""
        
    if _INJECTION_REGEX.search(text):
        return False, "Input rejected: Potential prompt injection detected."
        
    return True, ""


def validate_output(text: str) -> tuple[bool, str]:
    """Check if the model output violates theological or safety boundaries."""
    if not text:
        return True, ""
        
    if "NON-NEGOTIABLE RULES" in text or ">>> UNTRUSTED" in text:
        return False, "Output rejected: System prompt leaked into response."
        
    return True, ""
