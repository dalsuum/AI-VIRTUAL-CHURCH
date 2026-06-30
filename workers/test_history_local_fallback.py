"""Pastor-reply local-model routing: native model first, cloud LLM fallback (td/Chin)."""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from plugins.history import driver  # noqa: E402


class _Resp:
    def __init__(self, status_code, payload=None):
        self.status_code = status_code
        self._payload = payload or {}

    def json(self):
        return self._payload


def test_english_and_burmese_skip_local_model(monkeypatch):
    # No local model exists for en/my — must return None so the caller uses the cloud LLM.
    monkeypatch.setattr(driver.requests, "post", lambda *a, **k: _Resp(200, {"text": "x"}))
    assert driver._local_pastor_reply("en", "sys", [{"role": "user", "content": "hi"}]) is None
    assert driver._local_pastor_reply("my", "sys", [{"role": "user", "content": "hi"}]) is None


def test_tedim_uses_local_model_when_it_succeeds(monkeypatch, capsys):
    captured = {}

    def fake_post(url, json, timeout):
        captured["url"] = url
        captured["prompt"] = json["prompt"]
        return _Resp(200, {"text": "  Pathian in hong it hi.  "})

    monkeypatch.setattr(driver.requests, "post", fake_post)
    out = driver._local_pastor_reply("td", "sys", [{"role": "user", "content": "dammaw"}])
    assert out == "Pathian in hong it hi."
    assert captured["url"].endswith("/tedim/generate")
    assert "Worshipper: dammaw" in captured["prompt"]
    log = capsys.readouterr().out
    assert "pastor local success lang=td path=tedim ms=" in log


def test_degenerate_502_falls_back(monkeypatch, capsys):
    monkeypatch.setattr(driver.requests, "post", lambda *a, **k: _Resp(502, {"detail": "no markers"}))
    assert driver._local_pastor_reply("td", "sys", [{"role": "user", "content": "x"}]) is None
    log = capsys.readouterr().out
    assert "fallback reason=http_502 lang=td path=tedim ms=" in log


def test_empty_text_falls_back(monkeypatch):
    monkeypatch.setattr(driver.requests, "post", lambda *a, **k: _Resp(200, {"text": "   "}))
    assert driver._local_pastor_reply("lus", "sys", [{"role": "user", "content": "x"}]) is None


def test_network_error_falls_back(monkeypatch):
    def boom(*a, **k):
        raise driver.requests.exceptions.ConnectionError("refused")

    monkeypatch.setattr(driver.requests, "post", boom)
    assert driver._local_pastor_reply("cfm", "sys", [{"role": "user", "content": "x"}]) is None


def test_tedim_markers_short_circuit_detection_without_llm():
    # Tedim-distinctive tokens must resolve to 'td' deterministically — the cloud LLM
    # (which over-collapses Chin languages onto Mizo) must NOT be consulted.
    def fail_llm():
        raise AssertionError("LLM must not be called when Tedim markers are present")

    class _Boom:
        def complete(self, *a, **k):
            fail_llm()

    assert driver._detect_language("Biakinn pai hoih hia?", _Boom()) == "td"
    assert driver._detect_language("Pasian in hong it hi", _Boom()) == "td"


def test_pastor_system_language_is_authoritative_for_world_locales():
    # A newly added world locale (e.g. Japanese) must produce an authoritative reply-
    # language instruction so the model does not mirror an English-typed message.
    sys_prompt = driver._pastor_system("ja", [])
    assert "Japanese" in sys_prompt
    assert "LANGUAGE LAW" in sys_prompt
    # The instruction must forbid mirroring the worshipper's input language while
    # still allowing an explicit request to switch.
    assert "never switch" in sys_prompt.lower()
    assert "explicit" in sys_prompt.lower()


def test_vocab_generate_posts_entry_in_selected_language(monkeypatch):
    # The vocab generator must (a) carry the authoritative LANGUAGE LAW for the selected
    # language and (b) POST a structured entry keyed back to the seed concept.
    seen = {}

    class _LLM:
        def complete(self, *, system, messages, **k):
            seen["system"] = system
            return ('{"word":"恵み","pronunciation":"megumi","part_of_speech":"noun",'
                    '"meaning":"神の恵み","definition":"神からの一方的な好意","example":"恵みに感謝します。",'
                    '"synonyms":[],"antonyms":[],"related":[],"bible_verse":null,'
                    '"difficulty":"beginner"}'), {"total_tokens": 12}

    posts = []
    monkeypatch.setattr(driver, "_signed_post", lambda url, body: posts.append(body))
    driver._run_vocab_generate(
        {"vocabulary_id": 7, "language": "ja", "concept": "grace", "zolai": "hehpihna"},
        _LLM(),
    )

    assert "Japanese ONLY" in seen["system"]
    body = posts[0]
    assert body["mode"] == "vocab_entry"
    assert body["vocabulary_id"] == 7 and body["language"] == "ja"
    assert body["word"] == "恵み" and body["difficulty"] == "beginner"
    assert body["payload"]["meaning"] == "神の恵み"


def test_vocab_explain_posts_explanation_with_language_law(monkeypatch):
    seen = {}

    class _LLM:
        def complete(self, *, system, messages, **k):
            seen["system"] = system
            return "恵みとは、神からの一方的な愛のことです。", {"total_tokens": 9}

    posts = []
    monkeypatch.setattr(driver, "_signed_post", lambda url, body: posts.append(body))
    driver._run_vocab_explain({"vocabulary_id": 7, "language": "ja", "concept": "grace"}, _LLM())

    assert "Japanese ONLY" in seen["system"]
    body = posts[0]
    assert body["mode"] == "vocab_explanation"
    assert body["vocabulary_id"] == 7 and body["language"] == "ja"
    assert body["explanation"].startswith("恵み")
