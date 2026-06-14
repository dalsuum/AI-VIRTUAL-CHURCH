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
_MODEL_CLAUDE = os.getenv("AGENT_LLM_MODEL_CLAUDE",
                           os.getenv("AGENT_LLM_MODEL", "anthropic/claude-sonnet-4-6"))
_MODEL_GEMINI = os.getenv("AGENT_LLM_MODEL_GEMINI", "google/gemini-2.5-flash-preview-05-20")


def _get_agent_model() -> str:
    """Read agent provider from Redis (set by admin toggle) and return the model ID."""
    try:
        import redis as _redis
        client   = _redis.from_url(os.getenv("REDIS_URL", "redis://localhost:6379/0"))
        raw      = client.get("ai:agent_provider")
        provider = (raw.decode() if isinstance(raw, bytes) else raw) or "claude"
    except Exception:
        provider = "claude"
    return _MODEL_GEMINI if provider == "gemini" else _MODEL_CLAUDE

MAX_TURNS = 24   # hard cap — prevents runaway loops if the agent misbehaves


# ---------------------------------------------------------------------------
# Public entry point
# ---------------------------------------------------------------------------

def run_agent(job: dict) -> None:
    """Called by tasks.orchestrate when orchestration_mode == 'agent'."""
    token    = job["session_token"]
    language = job.get("language", "en")
    mood     = job.get("mood", "Hopeful")

    tools, handlers = _build_tools(job)

    system = _build_system_prompt(job)
    messages: list[dict] = [
        {"role": "user", "content": "Please conduct the worship service now."}
    ]

    model = _get_agent_model()
    print(f"[agent] starting session {token[:8]}… model={model}", flush=True)

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

def _build_system_prompt(job: dict) -> str:
    music_src  = job.get("music_source", "youtube")
    registered = job.get("is_registered", False)
    language   = job.get("language", "en")
    mood       = job.get("mood", "Hopeful")
    prayer     = job.get("prayer_text") or "(none provided)"
    history    = json.dumps(job.get("user_history") or {})

    sermon_note = (
        "For the sermon/message: call find_sermon_video to get a YouTube video — do NOT call generate_sermon."
        if music_src == "youtube"
        else "For the sermon: call generate_sermon with the scripture_ref from the plan."
    )

    welcome_note = (
        "This worshipper is registered — call generate_welcome and post it as the 'welcome' segment first."
        if registered
        else "This is a guest — skip the welcome segment."
    )

    return f"""You are the AI worship conductor for AI Virtual Church.
Your task: generate a complete, personal worship service for one worshipper.

## Worshipper context
- Mood: {mood}
- Prayer: {prayer}
- Language: {language}
- Music source: {music_src}
- Presenter gender: {job.get("presenter_gender", "female")}
- User history: {history}

## Service order — FOLLOW THIS EXACTLY for fastest door-open time
1. Call build_plan to get scripture_ref, music_prompt, music_query.
2. Call dispatch_music immediately (it is slow and async — start it first).
3. Call resolve_scripture with the scripture_ref from the plan.
4. Call generate_opening_prayer — post it as 'opening_prayer' RIGHT AWAY.
   (The doors open as soon as opening_prayer + music + narration are all ready.
    Getting the prayer posted fast is the single biggest driver of door-open time.)
5. {welcome_note}
6. Post the scripture text as the 'scripture' segment.
7. {sermon_note}
8. Generate the benediction and post it as 'benediction'.
9. Call finish_service when all segments above are posted.

## Rules
- Always call build_plan first to get the scripture_ref and music_prompt.
- After generating any text with an LLM tool, call review_content before posting.
  - If review fails, regenerate once with a slightly different angle.
  - If it fails again, post a neutral fallback message — never leave a segment empty.
- Post each segment as soon as it is ready; do not batch.
- Never include the worshipper's name in any generated text.
- The worship and closing_hymn music segments are handled automatically by dispatch_music.
- Call finish_service once all required text segments are posted.
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
    resp.raise_for_status()
    return resp.json()


# ---------------------------------------------------------------------------
# Tool registry
# ---------------------------------------------------------------------------

def _build_tools(job: dict) -> tuple[list[dict], dict[str, callable]]:
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

    def h_build_plan() -> dict:
        return llm_engine.build_intake_plan(
            user_name=job.get("user_name", ""),
            mood=mood,
            prayer_text=job.get("prayer_text"),
            language=language,
            music_source=job.get("music_source"),
            user_history=job.get("user_history"),
        )

    def h_generate_welcome() -> dict:
        text = llm_engine.generate_welcome(
            user_name=job.get("user_name", ""),
            mood=mood,
            language=language,
        )
        return {"text": text}

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

    def h_dispatch_music(music_prompt: str = "", music_query: str = "") -> dict:
        """Kick off async music generation — same task as the pipeline mode."""
        # Build a minimal plan with just what generate_music needs.
        plan = {
            "music_prompt": music_prompt,
            "music_query":  music_query,
            "music_lyrics": "",
        }
        # Respect the same MusicGen-queue fallback as the pipeline.
        effective_job = job
        if job.get("music_source") == "musicgen":
            try:
                import redis as _r
                client = _r.from_url(os.getenv("REDIS_URL", "redis://localhost:6379/0"))
                if int(client.llen("ai:music")) >= 1:
                    effective_job = {**job, "music_source": "hymn"}
                    print("[agent] MusicGen queue backed up, falling back to hymn", flush=True)
            except Exception:
                pass
        _celery_app.send_task("tasks.generate_music", args=[effective_job, plan])
        return {"ok": True, "note": "music dispatched asynchronously"}

    def h_finish_service() -> dict:
        return {"ok": True}

    handlers: dict[str, callable] = {
        "build_plan":             h_build_plan,
        "generate_welcome":       h_generate_welcome,
        "resolve_scripture":      h_resolve_scripture,
        "generate_opening_prayer": h_generate_opening_prayer,
        "generate_sermon":        h_generate_sermon,
        "generate_benediction":   h_generate_benediction,
        "find_sermon_video":      h_find_sermon_video,
        "review_content":         h_review_content,
        "post_text_segment":      h_post_text_segment,
        "post_youtube_sermon":    h_post_youtube_sermon,
        "dispatch_music":         h_dispatch_music,
        "finish_service":         h_finish_service,
    }

    schemas = [
        _fn("build_plan",
            "Build the service plan. Returns scripture_ref, music_prompt, music_query, "
            "music_lyrics, and preaching_query. Call this first.",
            {}),
        _fn("generate_welcome",
            "Generate a short, mood-aware welcome greeting for registered worshippers.",
            {}),
        _fn("resolve_scripture",
            "Fetch the full text of a Bible passage by reference.",
            {"scripture_ref": _p("string", True, "e.g. 'John 3:16' or 'Psalm 23:1-6'")}),
        _fn("generate_opening_prayer",
            "Generate the opening prayer text (2-3 paragraphs, no name).",
            {}),
        _fn("generate_sermon",
            "Generate the full sermon/message text (8 minutes of spoken prose).",
            {"scripture_ref": _p("string", True, "The reference from the plan, e.g. 'Romans 8:28'")}),
        _fn("generate_benediction",
            "Generate the closing benediction / sending text.",
            {}),
        _fn("find_sermon_video",
            "Find a YouTube sermon video matching the mood (use when music_source=youtube).",
            {"query": _p("string", False, "Search query hint from the plan's preaching_query")}),
        _fn("review_content",
            "Check generated text for safety and appropriateness before posting.",
            {"text": _p("string", True, "The generated text to review")}),
        _fn("post_text_segment",
            "Send a text segment to the frontend. Also triggers narration and avatar if enabled.",
            {
                "segment": _p("string", True, "Segment name: welcome|scripture|opening_prayer|sermon|benediction"),
                "text":    _p("string", True, "The final approved text content"),
            }),
        _fn("post_youtube_sermon",
            "Send a YouTube sermon video to the frontend as the sermon segment.",
            {
                "video_id": _p("string", True, "YouTube video ID"),
                "title":    _p("string", True, "Video title shown in the player"),
            }),
        _fn("dispatch_music",
            "Dispatch async music generation (worship + closing_hymn). Call early.",
            {
                "music_prompt": _p("string", False, "Suno style/mood prompt from the plan"),
                "music_query":  _p("string", False, "YouTube search query from the plan"),
            }),
        _fn("finish_service",
            "Signal that all required segments have been posted. Call last.",
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
