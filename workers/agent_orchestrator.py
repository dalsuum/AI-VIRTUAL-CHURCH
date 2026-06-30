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
import re
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


_OPENROUTER_KEY  = os.environ.get("OPENROUTER_API_KEY", "")
_OPENROUTER_URL  = os.getenv("OPENROUTER_BASE_URL", "https://openrouter.ai/api/v1")
_LARAVEL_WEBHOOK = os.environ.get("LARAVEL_WEBHOOK_URL", "")
_WORKER_SECRET   = os.environ.get("WORKER_WEBHOOK_SECRET", "")
_MUSIC_WEBHOOK   = _LARAVEL_WEBHOOK.replace("asset-ready", "music-track") if _LARAVEL_WEBHOOK else ""

# Model IDs used when the admin selects each provider.
# Both go through OpenRouter so no extra API key is needed.
_MODEL_CLAUDE  = os.getenv("AGENT_LLM_MODEL_CLAUDE",
                            os.getenv("AGENT_LLM_MODEL", "anthropic/claude-sonnet-4-6"))
_MODEL_GEMINI  = os.getenv("AGENT_LLM_MODEL_GEMINI",  "google/gemini-2.5-flash")
_MODEL_CHATGPT = os.getenv("AGENT_LLM_MODEL_CHATGPT", "openai/gpt-4o")


def _get_agent_model(language: str = "en") -> str:
    """Read agent provider from Redis (set by admin toggle) and return the OpenRouter model ID.

    RunPod routing is handled separately in _call_llm(); this always returns a valid
    OpenRouter model so the fallback path never fails with an unknown model ID."""
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


def _post_asset(token: str, **fields) -> None:
    payload = {"session_token": token, **fields}
    requests.post(
        _LARAVEL_WEBHOOK,
        json=payload,
        headers={"X-Worker-Secret": _WORKER_SECRET},
        timeout=30,
    ).raise_for_status()

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

    model = _get_agent_model(language)
    print(f"[agent] starting session {token[:8]}… model={model} scripture={plan.get('scripture_ref')}", flush=True)

    total_prompt_tokens = 0
    total_completion_tokens = 0

    for turn in range(MAX_TURNS):
        try:
            response = _call_llm(system, messages, tools, model, language)
        except Exception as exc:
            print(f"[agent] LLM call failed: {exc}", flush=True)
            # If the agent loop crashes, the worshipper is stranded. 
            # We break to let the worker finish, though the service may be incomplete.
            break
        
        usage = response.get("usage", {})
        total_prompt_tokens += usage.get("prompt_tokens", 0)
        total_completion_tokens += usage.get("completion_tokens", 0)

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
                raw_args = tc["function"].get("arguments") or "{}"
                # Handle cases where models might wrap JSON in markdown or include trailing commas
                raw_args = raw_args.strip().removeprefix("```json").removeprefix("```").removesuffix("```").strip()
                args = json.loads(raw_args)
            except json.JSONDecodeError:
                match = re.search(r"\{.*?\}", raw_args, re.DOTALL)
                if match:
                    try:
                        args = json.loads(match.group(0))
                    except json.JSONDecodeError:
                        args = {}
                else:
                    args = {}

            handler = handlers.get(name)
            if handler is None:
                result = {"error": f"unknown tool: {name}"}
            else:
                try:
                    result = handler(**args)
                except TypeError as exc:
                    result = {"error": f"Invalid arguments for {name}: {exc}"}
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
        # If we timed out, try to force-finish so the frontend doesn't spin forever
        try:
            handlers["finish_service"]()
        except: pass
        print(f"[agent] hit MAX_TURNS={MAX_TURNS} for {token[:8]}…", flush=True)

    print(f"[agent] session {token[:8]} ended. Total tokens used: prompt={total_prompt_tokens}, completion={total_completion_tokens}", flush=True)
    
    try:
        _post_asset(token, segment="telemetry_agent", asset_type="telemetry", prompt_tokens=total_prompt_tokens, completion_tokens=total_completion_tokens)
    except Exception as exc:
        print(f"[agent] failed to post token telemetry: {exc}", flush=True)


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
        "For the sermon: call find_and_post_sermon_video() (YouTube mode) — do NOT call generate_and_post_sermon."
        if music_src == "youtube"
        else f"For the sermon: call generate_and_post_sermon with scripture_ref='{scripture_ref}'."
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

## Your task — execute these steps IN THIS ORDER:

1. Call resolve_and_post_scripture(scripture_ref="{scripture_ref}").
2. Call generate_and_post_opening_prayer().
3. {sermon_note}
4. Call generate_and_post_benediction().
5. Call finish_service().

## Rules
- The tools automatically handle safety review and delivery to the frontend.
- Do not attempt to write the sermon or prayer text yourself; the tools will generate and deliver them.
- Post each segment immediately — do not wait to batch them.
- CRITICAL: If the user provides a prayer, extract their specific core keywords and include them in the `query` argument when calling find_sermon_video.
- Do not call dispatch_music, generate_welcome, or build_plan — those are already done.
"""


# ---------------------------------------------------------------------------
# LLM call
# ---------------------------------------------------------------------------

def _call_llm(system: str, messages: list[dict], tools: list[dict], model: str, language: str = "en") -> dict:
    full_messages = [{"role": "system", "content": system}] + messages

    def _attempt(base_url: str, api_key: str, resolved_model: str, provider: str) -> dict:
        max_attempts = 3
        for attempt in range(1, max_attempts + 1):
            try:
                resp = requests.post(
                    f"{base_url}/chat/completions",
                    headers={
                        "Authorization": f"Bearer {api_key}",
                        "Content-Type":  "application/json",
                    },
                    json={
                        "model":       resolved_model,
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
                        f"[agent] {provider} request failed status={resp.status_code} model={resolved_model} body={json.dumps(err, ensure_ascii=False)[:1000]}",
                        flush=True,
                    )
                resp.raise_for_status()
                return resp.json()
            except requests.exceptions.RequestException as exc:
                status_code = getattr(exc.response, "status_code", None)
                if attempt < max_attempts and (status_code in (429, 500, 502, 503, 504) or isinstance(exc, (requests.exceptions.ConnectionError, requests.exceptions.Timeout))):
                    time.sleep(2 ** attempt)
                    continue
                raise

    # Agent orchestration always uses OpenRouter — tool calling requires structured
    # function-call support that RunPod's streaming token format does not provide.
    # RunPod GPU is used for prose generation only (llm_engine._complete).
    return _attempt(_OPENROUTER_URL, _OPENROUTER_KEY, model, "OpenRouter")


# ---------------------------------------------------------------------------
# Tool registry
# ---------------------------------------------------------------------------

def _special_sunday_theme(job: dict) -> str | None:
    """Sermon-theme string from the special-Sunday bias on the job (English, LLM
    steering only). Mirrors tasks._special_sunday_theme without importing tasks."""
    special = job.get("special_sunday")
    if not isinstance(special, dict):
        return None
    title = (special.get("title") or special.get("key") or "").strip()
    tags = [t for t in (special.get("sermon_tags") or []) if isinstance(t, str) and t.strip()]
    if not title and not tags:
        return None
    return f"{title}: {', '.join(tags)}" if tags else title


def _special_sunday_manual(job: dict, segment: str) -> dict | None:
    """Curated 'manual' content for a segment when the active special Sunday's
    per-language mode is manual. Mirrors tasks._special_sunday_content."""
    special = job.get("special_sunday")
    if not isinstance(special, dict):
        return None
    content = special.get("content")
    if not isinstance(content, dict):
        return None
    seg = content.get(segment)
    if isinstance(seg, dict) and seg.get("mode") == "manual":
        return seg
    return None


def _special_sunday_query(job: dict, base_query: str) -> str:
    """Fold the observance's music_moods into a search query (sermon video)."""
    special = job.get("special_sunday")
    if not isinstance(special, dict):
        return base_query
    moods = [m for m in (special.get("music_moods") or []) if isinstance(m, str) and m.strip()]
    if not moods:
        return base_query
    extra = " ".join(moods)
    return f"{base_query} {extra}".strip() if base_query else extra


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

    _narrate_slot = 0

    def _seg_gender(segment: str) -> str:
        return gender if segment == "sermon" else ("female" if gender == "male" else "male")

    def _narrate_voice(g: str) -> str:
        """Mirrors tasks._narration_voice without importing tasks."""
        if narration_mode == "voicebox":
            return voicebox_engine
        return narrator.edge_voice(language, g)

    # ---- handlers --------------------------------------------------------

    def h_resolve_and_post_scripture(scripture_ref: str = "") -> dict:
        scripture_ref = scripture_ref or ""
        effective_ref = scripture_ref.strip() if scripture_ref.strip() else plan.get("scripture_ref", "")
        if effective_ref != scripture_ref:
            print(f"[agent] resolve_scripture missing/empty ref. Falling back to plan: {effective_ref!r}", flush=True)

        try:
            text = bible_api.resolve(effective_ref, lang=language)
            if not text and effective_ref != plan.get("scripture_ref", ""):
                fallback_ref = plan.get("scripture_ref", "")
                print(f"[agent] resolve_scripture invalid ref {effective_ref!r}. Falling back to plan: {fallback_ref!r}", flush=True)
                effective_ref = fallback_ref
                text = bible_api.resolve(effective_ref, lang=language)

            heading = (bible_api.book_title(effective_ref, lang=language) or effective_ref) if language != "en" else effective_ref
            full_text = f"{heading}\n\n{text}"
            return h_post_text_segment("scripture", full_text)
        except Exception as exc:  # noqa: BLE001
            print(f"[agent] resolve_scripture error for {effective_ref!r}: {exc}", flush=True)
            return {"error": str(exc)}

    def h_generate_and_post_opening_prayer() -> dict:
        text = llm_engine.generate_opening_prayer(
            user_name=job.get("user_name", ""),
            mood=mood,
            prayer_text=job.get("prayer_text"),
            language=language,
            user_history=job.get("user_history"),
        )
        return h_post_text_segment("opening_prayer", text)

    def h_generate_and_post_sermon(scripture_ref: str = "") -> dict:
        manual = _special_sunday_manual(job, "sermon")
        if manual and (manual.get("body") or "").strip():
            return h_post_text_segment("sermon", manual["body"].strip())
        minutes = 5 if job.get("music_source") == "musicgen" else 8
        text = llm_engine.generate_sermon(
            user_name=job.get("user_name", ""),
            mood=mood,
            scripture_ref=scripture_ref,
            language=language,
            target_minutes=minutes,
            prayer_text=job.get("prayer_text"),
            user_history=job.get("user_history"),
            theme=_special_sunday_theme(job),
        )
        return h_post_text_segment("sermon", text)

    def h_generate_and_post_benediction() -> dict:
        text = llm_engine.generate_benediction(
            user_name=job.get("user_name", ""),
            mood=mood,
            language=language,
            prayer_text=job.get("prayer_text"),
            user_history=job.get("user_history"),
        )
        return h_post_text_segment("benediction", text)

    def h_find_and_post_sermon_video(query: str = "") -> dict:
        # A curated manual sermon overrides the YouTube sermon video too.
        manual = _special_sunday_manual(job, "sermon")
        if manual and (manual.get("body") or "").strip():
            return h_post_text_segment("sermon", manual["body"].strip())
        query = query or ""
        past  = (job.get("user_history") or {}).get("past_video_ids", [])
        
        effective_query = query.strip() if query.strip() else plan.get("preaching_query", "")
        if effective_query != query:
            print(f"[agent] find_sermon_video missing/empty query. Falling back to plan: {effective_query!r}", flush=True)
        # A special Sunday biases the search toward the observance's moods.
        effective_query = _special_sunday_query(job, effective_query)

        try:
            video = _find_sermon_video(mood=mood, query=effective_query, language=language, excluded_ids=past)
            # Fallback policy: no native sermon -> serve an English one rather than
            # dropping the segment (translation deferred; English query is generic).
            if not video["found"] and language != "en":
                video = _find_sermon_video(mood=mood, query="", language="en", excluded_ids=past)
                if video["found"]:
                    print(f"[agent] no native {language!r} sermon — using English fallback", flush=True)
            if video["found"]:
                return h_post_youtube_sermon(video["video_id"], video["title"])
            return {"error": "no sermon video found in language or English"}
        except Exception as exc:
            return {"error": str(exc)}

    # Kept as internal helper, removed from agent tools
    def h_post_text_segment(segment: str = "", text: str = "") -> dict:
        segment = segment or ""
        text = text or ""
        if not segment:
            return {"error": "segment name is required"}
        if not text:
            return {"error": "text content is required"}

        # Security: Enforce review before posting. Do not trust the agent to call review_content.
        ok, reason = classifier.review(text)
        if not ok:
            return {"error": f"Content rejected by safety classifier: {reason}"}
            
        _post_asset(token, segment=segment, asset_type="text", text_payload=text)

        narrated = {"opening_prayer", "scripture", "sermon", "benediction"}
        if want_audio and segment in narrated:
            nonlocal _narrate_slot
            g = _seg_gender(segment)
            stagger_step = 0
            if narration_mode == "kokoro":
                stagger_step = 45
            elif language in ("my", "td") and narration_mode in ("edge_tts", "mms_tts"):
                stagger_step = int(os.getenv("MMS_TTS_STAGGER_SECONDS", "60"))
            _celery_app.send_task(
                "tasks.narrate",
                args=[token, segment, text, narration_mode, _narrate_voice(g), g, language],
                countdown=_narrate_slot,
            )
            _narrate_slot += stagger_step

        avatared = {"opening_prayer", "sermon", "benediction"}
        if segment in avatared:
            import avatar as _avatar
            _engine = _avatar.select_engine(
                did_enabled=job.get("avatar_enabled", True),
                local_enabled=job.get("local_avatar_enabled", False),
            )
            if _engine:
                _celery_app.send_task(
                    "tasks.render_avatar",
                    args=[token, segment, text, _seg_gender(segment), _engine],
                )

        return {"ok": True, "segment": segment}

    # Kept as internal helper
    def h_post_youtube_sermon(video_id: str = "", title: str = "") -> dict:
        video_id = video_id or ""
        title = title or ""
        _post_asset(token, segment="sermon", asset_type="youtube",
                    provider_ref=video_id, text_payload=title)
        return {"ok": True}

    def h_finish_service() -> dict:
        return {"ok": True}

    handlers: dict[str, callable] = {
        "resolve_and_post_scripture":       h_resolve_and_post_scripture,
        "generate_and_post_opening_prayer": h_generate_and_post_opening_prayer,
        "generate_and_post_sermon":         h_generate_and_post_sermon,
        "generate_and_post_benediction":    h_generate_and_post_benediction,
        "find_and_post_sermon_video":       h_find_and_post_sermon_video,
        "finish_service":                   h_finish_service,
    }

    schemas = [
        _fn("resolve_and_post_scripture",
            "Fetch and deliver the Bible passage to the frontend.",
            {"scripture_ref": _p("string", True, "e.g. 'John 3:16' or 'Psalm 23:1-6'")}),
        _fn("generate_and_post_opening_prayer",
            "Generate and deliver the opening prayer (2-3 paragraphs, no worshipper name).",
            {}),
        _fn("generate_and_post_sermon",
            "Generate and deliver the full sermon/message text (~8 minutes of spoken prose, no name).",
            {"scripture_ref": _p("string", True, "e.g. 'Romans 8:28'")}),
        _fn("generate_and_post_benediction",
            "Generate and deliver the closing benediction text.",
            {}),
        _fn("find_and_post_sermon_video",
            "Find and deliver a YouTube sermon video matching the mood (youtube mode only). MUST include specific thematic keywords from the user's prayer if provided.",
            {"query": _p("string", True, "A YouTube search query including 'Christian sermon' AND 1-2 specific keywords from the worshipper's prayer.")}),
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
