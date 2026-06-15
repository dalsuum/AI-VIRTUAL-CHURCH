"""
AI Agent Orchestrator for AI Virtual Church.

Replaces the hard-coded pipeline with a Claude agent that reasons about what to
generate, in what order, and whether to retry poor-quality output.  The pipeline
mode is kept intact — the admin toggle in Settings routes each job to one or the
other.

Uses OpenRouter (same OPENROUTER_API_KEY the rest of the workers use) with the
model set by AGENT_LLM_MODEL (default: anthropic/claude-sonnet-4-6).

Circular-import note: this module is imported by tasks/__init__.py.  It must
never import from tasks/__init__.py.  All Celery task dispatch goes through
app.send_task() on the tasks.celery_app.app object (routes defined there).
"""

from __future__ import annotations

import json
import os
import sys
import time

import requests

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import bible_api          # noqa: E402
import classifier         # noqa: E402
import llm_engine         # noqa: E402
import narrator           # noqa: E402
import storage            # noqa: E402
from strategies import get_strategy, MusicResult   # noqa: E402
from strategies.youtube_strategy import find_sermon_video as _find_sermon_video  # noqa: E402
from tasks.celery_app import app as _celery_app    # noqa: E402


_OPENROUTER_KEY  = os.environ["OPENROUTER_API_KEY"]
_OPENROUTER_URL  = os.getenv("OPENROUTER_BASE_URL", "https://openrouter.ai/api/v1")
_LARAVEL_WEBHOOK = os.environ["LARAVEL_WEBHOOK_URL"]
_WORKER_SECRET   = os.environ["WORKER_WEBHOOK_SECRET"]
_MUSIC_WEBHOOK   = _LARAVEL_WEBHOOK.replace("asset-ready", "music-track")

# Model IDs used when the admin selects each provider.
# Both go through OpenRouter so no extra API key is needed.
_MODEL_CLAUDE  = os.getenv("AGENT_LLM_MODEL_CLAUDE",
                            os.getenv("AGENT_LLM_MODEL", "anthropic/claude-sonnet-4-6"))
_MODEL_GEMINI  = os.getenv("AGENT_LLM_MODEL_GEMINI",  "google/gemini-2.5-flash")
_MODEL_CHATGPT = os.getenv("AGENT_LLM_MODEL_CHATGPT", "openai/gpt-4o")


def _get_agent_model() -> str:
    """Read agent provider from Redis (set by admin toggle) and return the model ID."""
    try:
        import redis as _redis
        client   = _redis.from_url(os.getenv("REDIS_URL", "redis://localhost:6379/0"))
        raw      = client.get("ai:agent_provider")
        provider = (raw.decode() if isinstance(raw, bytes) else raw) or "claude"
    except Exception:
        provider = "claude"
    if provider == "gemini":
        return _MODEL_GEMINI
    if provider == "chatgpt":
        return _MODEL_CHATGPT
    return _MODEL_CLAUDE

MAX_TURNS = 24   # hard cap — prevents runaway loops if the agent misbehaves


# ---------------------------------------------------------------------------
# Public entry point
# ---------------------------------------------------------------------------

def run_agent(job: dict) -> None:
    """Called by tasks.orchestrate when orchestration_mode == 'agent'."""
    token    = job["session_token"]
    language = job.get("language", "en")
    mood     = job.get("mood", "Hopeful")

    # ── Step 1: build the intake plan (one LLM call, same as pipeline) ──────
    # This gives us scripture_ref and music details before the agent loop starts.
    plan = llm_engine.build_intake_plan(
        user_name=job.get("user_name", ""),
        mood=mood,
        prayer_text=job.get("prayer_text"),
        language=language,
        music_source=job.get("music_source"),
        user_history=job.get("user_history"),
    )

    # ── Step 2: pre-dispatch music + welcome immediately ────────────────────
    # Music generation is slow (Suno / YouTube / hymn lookup). Dispatching it
    # NOW — before the agent loop — mirrors the pipeline's parallel fan-out and
    # guarantees worship + closing_hymn are always posted regardless of how the
    # agent behaves.  The agent no longer needs a dispatch_music tool.
    effective_job = job
    if job.get("music_source") == "musicgen":
        try:
            import redis as _r
            _rc = _r.from_url(os.getenv("REDIS_URL", "redis://localhost:6379/0"))
            if int(_rc.llen("ai:music")) >= 1:
                effective_job = {**job, "music_source": "hymn"}
                print("[agent] MusicGen queue backed up, falling back to hymn", flush=True)
        except Exception:
            pass
    _celery_app.send_task("tasks.generate_music", args=[effective_job, plan])

    if job.get("is_registered"):
        _celery_app.send_task("tasks.generate_welcome", args=[job])

    # ── Step 3: agent handles text segments only ─────────────────────────────
    tools, handlers = _build_tools(job, plan)
    system = _build_system_prompt(job, plan)
    messages: list[dict] = [
        {"role": "user", "content": "Please generate the text segments for this worship service."}
    ]

    model = _get_agent_model()
    print(f"[agent] starting session {token[:8]}… model={model} scripture={plan.get('scripture_ref')}", flush=True)

    for turn in range(MAX_TURNS):
        response = _call_llm(system, messages, tools, model)
        choice   = response["choices"][0]
        msg      = choice["message"]
        messages.append(msg)

        tool_calls = msg.get("tool_calls") or []
        if not tool_calls:
            # Agent gave a plain text reply — it is either done or confused.
            finish = choice.get("finish_reason", "")
            print(f"[agent] no tool calls at turn {turn} (finish={finish}), stopping", flush=True)
            break

        tool_results: list[dict] = []
        done = False

        for tc in tool_calls:
            name   = tc["function"]["name"]
            try:
                args = json.loads(tc["function"].get("arguments") or "{}")
            except json.JSONDecodeError:
                args = {}

            handler = handlers.get(name)
            if handler is None:
                result = {"error": f"unknown tool: {name}"}
            else:
                try:
                    result = handler(**args)
                except Exception as exc:  # noqa: BLE001
                    result = {"error": str(exc)}
                    print(f"[agent] tool {name} raised: {exc}", flush=True)

            tool_results.append({
                "role":        "tool",
                "tool_call_id": tc["id"],
                "content":     json.dumps(result),
            })

            if name == "finish_service":
                done = True

        messages.extend(tool_results)

        if done:
            print(f"[agent] service complete at turn {turn}", flush=True)
            break
    else:
        print(f"[agent] hit MAX_TURNS={MAX_TURNS} for {token[:8]}…", flush=True)


# ---------------------------------------------------------------------------
# System prompt
# ---------------------------------------------------------------------------

def _build_system_prompt(job: dict, plan: dict) -> str:
    music_src    = job.get("music_source", "youtube")
    language     = job.get("language", "en")
    mood         = job.get("mood", "Hopeful")
    prayer       = job.get("prayer_text") or "(none provided)"
    scripture_ref = plan.get("scripture_ref", "John 3:16")

    sermon_note = (
        "For the sermon: call find_sermon_video (YouTube mode) — do NOT call generate_sermon."
        if music_src == "youtube"
        else f"For the sermon: call generate_sermon with scripture_ref='{scripture_ref}'."
    )

    return f"""You are the AI worship conductor for AI Virtual Church.
Your job: generate the TEXT segments for one worshipper's personal service.

## Already handled for you (do NOT call these)
- Worship music (worship + closing_hymn) is already being generated asynchronously.
- Welcome greeting (if registered) is already dispatched.
- You do NOT need to call dispatch_music or generate_welcome.

## Worshipper context
- Mood: {mood}
- Prayer: {prayer}
- Language: {language}
- Scripture reference (already chosen): {scripture_ref}

## Your task — generate these 4 segments IN THIS ORDER:

1. Call resolve_scripture("{scripture_ref}") → post result as 'scripture'.
2. Call generate_opening_prayer → review_content → post as 'opening_prayer'.
   POST THIS AS FAST AS POSSIBLE — it opens the doors for the worshipper.
3. {sermon_note} → review_content → post as 'sermon'.
4. Call generate_benediction → review_content → post as 'benediction'.
5. Call finish_service.

## Rules
- After every generate_* call, ALWAYS call review_content before posting.
- If review fails: regenerate once with a different angle. If still failing: post a short neutral fallback.
- Never include the worshipper's name in generated text.
- Post each segment immediately — do not wait to batch them.
- Do not call dispatch_music, generate_welcome, or build_plan — those are already done.
"""


# ---------------------------------------------------------------------------
# LLM call
# ---------------------------------------------------------------------------

def _call_llm(system: str, messages: list[dict], tools: list[dict], model: str) -> dict:
    full_messages = [{"role": "system", "content": system}] + messages
    resp = requests.post(
        f"{_OPENROUTER_URL}/chat/completions",
        headers={
            "Authorization": f"Bearer {_OPENROUTER_KEY}",
            "Content-Type":  "application/json",
        },
        json={
            "model":       model,
            "messages":    full_messages,
            "tools":       tools,
            "tool_choice": "auto",
            "max_tokens":  4096,
        },
        timeout=120,
    )
    if not resp.ok:
        try:
            err = resp.json().get("error", resp.json())
        except ValueError:
            err = (resp.text or "")[:1000]
        print(
            f"[agent] OpenRouter request failed status={resp.status_code} model={model} body={json.dumps(err, ensure_ascii=False)[:1000]}",
            flush=True,
        )
    resp.raise_for_status()
    return resp.json()


# ---------------------------------------------------------------------------
# Tool registry
# ---------------------------------------------------------------------------

def _build_tools(job: dict, plan: dict) -> tuple[list[dict], dict[str, callable]]:
    """Return (OpenAI-format tool schemas, {name: handler}) bound to this job."""
    token    = job["session_token"]
    language = job.get("language", "en")
    mood     = job.get("mood", "Hopeful")
    gender   = job.get("presenter_gender", "female")

    narration_mode  = job.get("narration_mode")
    voicebox_engine = job.get("voicebox_engine", "qwen")

    _SERVER_MODES = {"openai", "kokoro", "edge_tts", "mms_tts", "voicebox"}
    want_audio = bool(
        job.get("narration_enabled", True)
        and narration_mode in _SERVER_MODES
        and narrator.is_enabled(narration_mode)
    )

    def _seg_gender(segment: str) -> str:
        return gender if segment == "sermon" else ("female" if gender == "male" else "male")

    def _narrate_voice(g: str) -> str:
        """Mirrors tasks._narration_voice without importing tasks."""
        if narration_mode == "voicebox":
            return voicebox_engine
        # Mirrors tasks._edge_voice
        suffix = g.upper()
        if language == "my":
            default = "my-MM-ThihaNeural" if g == "male" else "my-MM-NilarNeural"
            return (
                os.getenv(f"EDGE_TTS_VOICE_MY_{suffix}")
                or os.getenv("EDGE_TTS_VOICE_MY")
                or default
            )
        if language == "td":
            default = "en-US-GuyNeural" if g == "male" else "en-US-AriaNeural"
            return os.getenv("EDGE_TTS_VOICE_TD", default)
        default = "en-US-GuyNeural" if g == "male" else "en-US-AriaNeural"
        return os.getenv(f"EDGE_TTS_VOICE_{suffix}") or os.getenv("EDGE_TTS_VOICE", default)

    def _post_asset(**fields) -> None:
        payload = {"session_token": token, **fields}
        requests.post(
            _LARAVEL_WEBHOOK,
            json=payload,
            headers={"X-Worker-Secret": _WORKER_SECRET},
            timeout=30,
        ).raise_for_status()

    # ---- handlers --------------------------------------------------------

    def h_resolve_scripture(scripture_ref: str) -> dict:
        try:
            text    = bible_api.resolve(scripture_ref, lang=language)
            heading = (bible_api.book_title(scripture_ref, lang=language) or scripture_ref) if language != "en" else scripture_ref
            return {"heading": heading, "text": text, "full": f"{heading}\n\n{text}"}
        except Exception as exc:  # noqa: BLE001
            return {"heading": scripture_ref, "text": "", "full": scripture_ref, "error": str(exc)}

    def h_generate_opening_prayer() -> dict:
        text = llm_engine.generate_opening_prayer(
            user_name=job.get("user_name", ""),
            mood=mood,
            prayer_text=job.get("prayer_text"),
            language=language,
            user_history=job.get("user_history"),
        )
        return {"text": text}

    def h_generate_sermon(scripture_ref: str) -> dict:
        minutes = 5 if job.get("music_source") == "musicgen" else 8
        text = llm_engine.generate_sermon(
            user_name=job.get("user_name", ""),
            mood=mood,
            scripture_ref=scripture_ref,
            language=language,
            target_minutes=minutes,
            prayer_text=job.get("prayer_text"),
            user_history=job.get("user_history"),
        )
        return {"text": text}

    def h_generate_benediction() -> dict:
        text = llm_engine.generate_benediction(
            user_name=job.get("user_name", ""),
            mood=mood,
            language=language,
            prayer_text=job.get("prayer_text"),
            user_history=job.get("user_history"),
        )
        return {"text": text}

    def h_find_sermon_video(query: str = "") -> dict:
        past  = (job.get("user_history") or {}).get("past_video_ids", [])
        video = _find_sermon_video(mood=mood, query=query, language=language, excluded_ids=past)
        return {"video_id": video["video_id"], "title": video["title"]}

    def h_review_content(text: str) -> dict:
        ok, reason = classifier.review(text)
        return {"ok": ok, "reason": reason or ""}

    def h_post_text_segment(segment: str, text: str) -> dict:
        _post_asset(segment=segment, asset_type="text", text_payload=text)

        narrated = {"opening_prayer", "scripture", "sermon", "benediction"}
        if want_audio and segment in narrated:
            g = _seg_gender(segment)
            stagger = 0
            if narration_mode == "kokoro":
                stagger = 45
            elif language in ("my", "td") and narration_mode in ("edge_tts", "mms_tts"):
                stagger = int(os.getenv("MMS_TTS_STAGGER_SECONDS", "5"))
            _celery_app.send_task(
                "tasks.narrate",
                args=[token, segment, text, narration_mode, _narrate_voice(g), g, language],
                countdown=stagger,
            )

        avatared = {"opening_prayer", "sermon", "benediction"}
        if job.get("avatar_enabled", True) and segment in avatared:
            import avatar as _avatar
            if _avatar.is_enabled():
                _celery_app.send_task(
                    "tasks.render_avatar",
                    args=[token, segment, text, _seg_gender(segment)],
                )

        return {"ok": True, "segment": segment}

    def h_post_youtube_sermon(video_id: str, title: str) -> dict:
        _post_asset(segment="sermon", asset_type="youtube",
                    provider_ref=video_id, text_payload=title)
        return {"ok": True}

    def h_finish_service() -> dict:
        return {"ok": True}

    # build_plan, generate_welcome, dispatch_music removed — handled before the loop
    handlers: dict[str, callable] = {
        "resolve_scripture":       h_resolve_scripture,
        "generate_opening_prayer": h_generate_opening_prayer,
        "generate_sermon":         h_generate_sermon,
        "generate_benediction":    h_generate_benediction,
        "find_sermon_video":       h_find_sermon_video,
        "review_content":          h_review_content,
        "post_text_segment":       h_post_text_segment,
        "post_youtube_sermon":     h_post_youtube_sermon,
        "finish_service":          h_finish_service,
    }

    schemas = [
        _fn("resolve_scripture",
            "Fetch the full text of a Bible passage by reference.",
            {"scripture_ref": _p("string", True, "e.g. 'John 3:16' or 'Psalm 23:1-6'")}),
        _fn("generate_opening_prayer",
            "Generate the opening prayer text (2-3 paragraphs, no worshipper name).",
            {}),
        _fn("generate_sermon",
            "Generate the full sermon/message text (~8 minutes of spoken prose, no name).",
            {"scripture_ref": _p("string", True, "e.g. 'Romans 8:28'")}),
        _fn("generate_benediction",
            "Generate the closing benediction text.",
            {}),
        _fn("find_sermon_video",
            "Find a YouTube sermon video matching the mood (youtube music_source only).",
            {"query": _p("string", False, "Optional search hint")}),
        _fn("review_content",
            "Safety-check generated text before posting. Call after every generate_* call.",
            {"text": _p("string", True, "The generated text to check")}),
        _fn("post_text_segment",
            "Deliver a text segment to the frontend (also triggers narration and avatar).",
            {
                "segment": _p("string", True, "scripture | opening_prayer | sermon | benediction"),
                "text":    _p("string", True, "The reviewed text content"),
            }),
        _fn("post_youtube_sermon",
            "Deliver a YouTube video as the sermon segment.",
            {
                "video_id": _p("string", True, "YouTube video ID"),
                "title":    _p("string", True, "Video title"),
            }),
        _fn("finish_service",
            "Call this once all 4 text segments are posted.",
            {}),
    ]

    return schemas, handlers


# ---------------------------------------------------------------------------
# Schema helpers
# ---------------------------------------------------------------------------

def _p(type_: str, required: bool, description: str = "") -> dict:
    return {"type": type_, "required": required, "description": description}


def _fn(name: str, description: str, params: dict[str, dict]) -> dict:
    properties: dict = {}
    required: list[str] = []
    for k, v in params.items():
        properties[k] = {"type": v["type"]}
        if v.get("description"):
            properties[k]["description"] = v["description"]
        if v.get("required"):
            required.append(k)
    return {
        "type": "function",
        "function": {
            "name":        name,
            "description": description,
            "parameters": {
                "type":       "object",
                "properties": properties,
                "required":   required,
            },
        },
    }
