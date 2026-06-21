"""Tool Registry (Phase 2.1/6) — allow-list enforcement.

The v1 Bible Study orchestrator resolves scripture DETERMINISTICALLY (the model
cites references; the engine resolves them — see Phase 8), so it does not expose
arbitrary tool-calling to the model. This registry still enforces the allow-list
contract for any future agentic use: a handler may run only if its tool name is in
the module's allow-list. Handlers are resolved from a static code-side map — never
from user input — so there is no dynamic execution.
"""

from __future__ import annotations

from . import rag


class ToolRegistry:
    def __init__(self, allowed_names: list[str], translation: str):
        self._allowed = set(allowed_names or [])
        self._translation = translation
        # Static handler map keyed by registry name (handler_ref). No eval/import.
        self._handlers = {
            "bible_study.resolve_scripture": self._resolve_scripture,
            "bible_study.cite_verse": self._resolve_scripture,
            "bible_study.search_commentary": self._search_commentary,
            "bible_study.finish_round": lambda **_: {"ok": True},
        }

    def invoke(self, name: str, handler_ref: str, **kwargs) -> dict:
        if name not in self._allowed:
            return {"error": f"tool '{name}' not in module allow-list"}
        handler = self._handlers.get(handler_ref)
        if handler is None:
            return {"error": f"unknown handler '{handler_ref}'"}
        return handler(**kwargs)

    def _resolve_scripture(self, scripture_ref: str = "", **_) -> dict:
        verses = rag.scripture_for_refs([scripture_ref], self._translation)
        return {"verses": verses}

    def _search_commentary(self, query: str = "", **_) -> dict:
        return {"results": []}  # commentary corpus is a future RAG source
