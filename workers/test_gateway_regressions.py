import os
import sys

sys.path.insert(0, os.path.abspath(os.path.dirname(__file__)))

from core.ai_gateway import DEFAULT_OPENROUTER_URL, _resolve_base_url  # noqa: E402
from core.tool_registry import ToolRegistry  # noqa: E402


def test_gateway_ignores_local_chaos_url_without_opt_in(monkeypatch):
    monkeypatch.delenv("ALLOW_LOCAL_OPENROUTER", raising=False)

    assert _resolve_base_url("http://127.0.0.1:8080") == DEFAULT_OPENROUTER_URL


def test_gateway_allows_local_chaos_url_with_explicit_opt_in(monkeypatch):
    monkeypatch.setenv("ALLOW_LOCAL_OPENROUTER", "1")

    assert _resolve_base_url("http://127.0.0.1:8080") == "http://127.0.0.1:8080"


def test_tool_registry_keeps_legacy_allow_list_contract():
    registry = ToolRegistry(["finish_round"], "kjv")

    assert registry.invoke("finish_round", "bible_study.finish_round") == {"ok": True}
    assert "not in module allow-list" in registry.invoke(
        "resolve_scripture",
        "bible_study.resolve_scripture",
        scripture_ref="John 3:16",
    )["error"]
