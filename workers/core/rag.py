"""RAG Layer (Phase 2.1/6) — extensible retrieval behind one interface.

v1 ships the Scripture source (exact, licensed text via the Scripture Engine). The
interface is stacked so Commentary and prior-session Memory sources can be added
later without touching plugins. Scripture retrieval is the only source wired now.
"""

from __future__ import annotations

from . import scripture


def scripture_for_refs(refs: list[str], translation: str) -> list[dict]:
    """Resolve explicit references to canonical verse dicts (deduped, ordered)."""
    out: list[dict] = []
    seen: set[str] = set()
    for ref in refs or []:
        vo = scripture.resolve_ref(ref, translation)
        if vo.canonical_id and vo.canonical_id not in seen:
            seen.add(vo.canonical_id)
            out.append(vo.to_dict())
    return out


def scripture_in_text(text: str, translation: str, max_refs: int = 8) -> list[dict]:
    """Detect + resolve references embedded in prose (for inline verse cards)."""
    return [vo.to_dict() for vo in scripture.detect_refs(text, translation, max_refs)]
