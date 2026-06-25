"""Tool Registry (Phase 2.1/6) -- allow-list enforcement.

The v1 Bible Study orchestrator resolves scripture deterministically: the model
cites references, and the engine resolves them. This registry keeps that static
allow-list contract for future agentic use, while also supporting the newer
generic registration helpers used by gateway experiments.
"""

from __future__ import annotations

from typing import Any, Callable

from . import rag


class ToolRegistry:
    def __init__(self, allowed_names: list[str] | None = None, translation: str = "kjv"):
        self._allowed = set(allowed_names or [])
        self._translation = translation
        self._tools: dict[str, dict[str, Any]] = {}
        self._handlers: dict[str, Callable[..., Any]] = {
            "bible_study.resolve_scripture": self._resolve_scripture,
            "bible_study.cite_verse": self._resolve_scripture,
            "bible_study.search_commentary": self._search_commentary,
            "bible_study.finish_round": lambda **_: {"ok": True},
        }

    def register_tool(self, name: str, schema: dict[str, Any], handler: Callable[..., Any]) -> None:
        """Register a tool with its OpenAI-compatible schema and Python handler."""
        self._tools[name] = {
            "schema": schema,
            "handler": handler,
        }
        self._handlers[name] = handler

    def get_all_schemas(self) -> list[dict[str, Any]]:
        """Return all registered schemas formatted for the LLM API."""
        return [
            {
                "type": "function",
                "function": {
                    "name": name,
                    **tool["schema"],
                },
            }
            for name, tool in self._tools.items()
        ]

    def execute_tool(self, name: str, **kwargs) -> Any:
        """Execute a generically registered tool by name."""
        if name not in self._tools:
            raise ValueError(f"Tool '{name}' is not registered.")

        handler = self._tools[name]["handler"]
        return handler(**kwargs)

    def invoke(self, name: str, handler_ref: str, **kwargs) -> dict:
        """Invoke a code-side handler only when its public tool name is allowed."""
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
        return {"results": []}  # Commentary corpus is a future RAG source.


registry = ToolRegistry()
