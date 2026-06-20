"""
Celery tasks. The orchestrator fans intake out into parallel generation tasks; each
task posts its finished asset back to Laravel's /internal/asset-ready webhook, which
in turn pushes it to the client over WebSockets.
"""

from __future__ import annotations

import os
import sys
import tempfile
import time

import requests

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import avatar  # noqa: E402
import bible_api  # noqa: E402
import classifier  # noqa: E402
import llm_engine  # noqa: E402
import narrator  # noqa: E402
from tasks.celery_burmese_tasks import localize_segment_burmese, narrate_burmese  # noqa: E402, F401
from tasks.celery_tedim_tasks import localize_segment_tedim, narrate_tedim  # noqa: E402, F401
import storage  # noqa: E402
from strategies import MusicResult, get_strategy  # noqa: E402
from strategies.youtube_strategy import find_sermon_video  # noqa: E402
from tasks.celery_app import app  # noqa: E402

LARAVEL_WEBHOOK = os.environ["LARAVEL_WEBHOOK_URL"]  # e.g. https://api.host/api/internal/asset-ready
WORKER_SECRET = os.environ["WORKER_WEBHOOK_SECRET"]
# Sibling internal endpoint that registers a freshly generated song in the reusable
# mood pool. Same host/secret as the asset-ready webhook, different final path.
MUSIC_TRACK_WEBHOOK = LARAVEL_WEBHOOK.replace("asset-ready", "music-track")


def _post_asset(session_token: str, segment: str, **fields) -> None:
    payload = {"session_token": session_token, "segment": segment, **fields}
    requests.post(
        LARAVEL_WEBHOOK,
        json=payload,
        headers={"X-Worker-Secret": WORKER_SECRET},
        timeout=30,
    ).raise_for_status()


def _post_music_track(*, mood: str, language: str, provider_ref: str, storage_key: str, title: str | None, lyrics: str | None = None) -> None:
    """Register a fresh Suno track in the reusable mood pool. Best-effort: the pool is
    an optimization for *future* services, so a failure here must never break this one."""
    try:
        requests.post(
            MUSIC_TRACK_WEBHOOK,
            json={"mood": mood, "language": language, "provider_ref": provider_ref, "storage_key": storage_key, "title": title, "lyrics": lyrics},
            headers={"X-Worker-Secret": WORKER_SECRET},
            timeout=30,
        ).raise_for_status()
    except Exception as exc:  # noqa: BLE001 — pool registration is non-critical
        print(f"[music] pool registration failed for {provider_ref}: {exc}", flush=True)


def _musicgen_queue_depth() -> int:
    """Return the number of tasks waiting in the MusicGen queue."""
    try:
        import redis as _redis
        client = _redis.from_url(os.getenv("REDIS_URL", "redis://localhost:6379/0"))
        return int(client.llen("ai:music"))
    except Exception:
        return 0


SERVER_NARRATION_MODES = {"openai", "kokoro", "edge_tts", "mms_tts", "voicebox"}
NARRATED_SEGMENTS = {"opening_prayer", "scripture", "sermon", "benediction"}


def _special_sunday_theme(job: dict) -> str | None:
    """Build a sermon-theme string from the special-Sunday bias on the job, e.g.
    "Easter Sunday: resurrection, victory, empty tomb". Always English (it steers
    the LLM, never reaches the worshipper) and uses the language-neutral key/tags.
    Returns None outside any observance window."""
    special = job.get("special_sunday")
    if not isinstance(special, dict):
        return None
    # The English title is the clearest LLM anchor; fall back to the key.
    title = (special.get("title") or special.get("key") or "").strip()
    tags = [t for t in (special.get("sermon_tags") or []) if isinstance(t, str) and t.strip()]
    if not title and not tags:
        return None
    return f"{title}: {', '.join(tags)}" if tags else title


def _special_sunday_content(job: dict, segment: str) -> dict | None:
    """Return the curated 'manual' content for a segment ('sermon' | 'music') when
    the active special Sunday's per-language mode is manual AND an entry resolved.
    None means run the normal AI/bias path."""
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


def _youtube_id(ref: str) -> str:
    """Extract an 11-char YouTube id from a URL or bare id; return ref unchanged
    if it doesn't look like either (the player tolerates a raw id)."""
    import re
    ref = (ref or "").strip()
    m = re.search(r"(?:v=|youtu\.be/|/embed/|/shorts/)([A-Za-z0-9_-]{11})", ref)
    if m:
        return m.group(1)
    if re.fullmatch(r"[A-Za-z0-9_-]{11}", ref):
        return ref
    return ref


def _deliver_manual_song(job: dict, song: dict) -> bool:
    """Serve an admin-curated special-Sunday song for the worship + closing_hymn
    segments. Supports all four source kinds. Returns True on success; False lets
    the caller fall back to the normal mood-selected worship."""
    token    = job["session_token"]
    stype    = (song.get("source_type") or "").strip()
    title    = song.get("title") or "Worship"
    lyrics   = song.get("lyrics")
    ref      = (song.get("source_ref") or "").strip()
    language = job.get("language", "en")

    def _post_both(**fields):
        for seg in ("worship", "closing_hymn"):
            _post_asset(token, seg, text_payload=title, lyrics=lyrics, **fields)

    try:
        if stype == "youtube":
            _post_both(asset_type="youtube", provider_ref=_youtube_id(ref))
            return True

        if stype == "audio":
            # A direct hosted URL is already browser-playable; pass it straight
            # through as the storage_key the player loads.
            _post_both(asset_type="audio", storage_key=ref)
            return True

        if stype == "hymn":
            import song_library
            match = next(
                (s for s in song_library.get_songs(language=language) if str(s.get("id")) == str(ref)),
                None,
            )
            if match is None:
                return False
            url = (match.get("url") or "").strip()
            use_lyrics = lyrics or match.get("lyrics")
            use_title  = title if song.get("title") else (match.get("title") or title)
            if not url:
                return False
            if "youtu" in url:
                for seg in ("worship", "closing_hymn"):
                    _post_asset(token, seg, asset_type="youtube", provider_ref=_youtube_id(url),
                                text_payload=use_title, lyrics=use_lyrics)
            else:
                for seg in ("worship", "closing_hymn"):
                    _post_asset(token, seg, asset_type="audio", storage_key=url,
                                text_payload=use_title, lyrics=use_lyrics)
            return True

        if stype == "suno":
            from strategies import get_strategy
            strategy = get_strategy("suno", language=language)
            result = strategy.fetch(mood=job.get("mood", ""), prompt=ref, query="")
            if result is None:
                return False
            if result.asset_type == "audio" and result.storage_key:
                result.storage_key = storage.presign(result.storage_key, expires=6 * 3600)
            for seg in ("worship", "closing_hymn"):
                _post_asset(token, seg, asset_type=result.asset_type, storage_key=result.storage_key,
                            provider_ref=result.provider_ref, text_payload=title or result.title,
                            lyrics=lyrics or result.lyrics, timings=result.timings)
            return True
    except Exception as exc:  # noqa: BLE001 — degrade to normal worship, never crash
        print(f"[music] manual special-Sunday song ({stype}) failed: {exc}", flush=True)

    return False


def _special_sunday_music_query(job: dict, base_query: str) -> str:
    """Fold the observance's `music_moods` into the hymn/worship search query so
    the mood→hymn matcher leans toward themed worship. Leaves the base query
    intact when no special Sunday is active."""
    special = job.get("special_sunday")
    if not isinstance(special, dict):
        return base_query
    moods = [m for m in (special.get("music_moods") or []) if isinstance(m, str) and m.strip()]
    if not moods:
        return base_query
    extra = " ".join(moods)
    return f"{base_query} {extra}".strip() if base_query else extra


def _wants_server_narration(job: dict) -> bool:
    mode = job.get("narration_mode")
    return bool(
        job.get("narration_enabled", True)
        and mode in SERVER_NARRATION_MODES
        and narrator.is_enabled(mode)
    )


def _segment_gender(segment: str, presenter_gender: str = "female") -> str:
    """Sermon uses the chosen presenter gender; support segments use the opposite."""
    return presenter_gender if segment == "sermon" else ("female" if presenter_gender == "male" else "male")


def _edge_voice(language: str, gender: str) -> str:
    suffix = gender.upper()
    if language == "my":
        default = "my-MM-ThihaNeural" if gender == "male" else "my-MM-NilarNeural"
        return (
            os.getenv(f"EDGE_TTS_VOICE_MY_{suffix}")
            or os.getenv("EDGE_TTS_VOICE_MY")
            or default
        )
    if language == "td":
        default = "en-US-GuyNeural" if gender == "male" else "en-US-AriaNeural"
        return os.getenv("EDGE_TTS_VOICE_TD", default)
    default = "en-US-GuyNeural" if gender == "male" else "en-US-AriaNeural"
    return os.getenv(f"EDGE_TTS_VOICE_{suffix}") or os.getenv("EDGE_TTS_VOICE", default)


def _narration_voice(mode: str, voicebox_engine: str, language: str, gender: str) -> str:
    """Return the voice/engine param for the narrate task based on provider mode."""
    if mode == "voicebox":
        return voicebox_engine
    return _edge_voice(language, gender)


def _narration_stagger(mode: str, language: str) -> int:
    if mode == "kokoro":
        return 45
    if language in ("my", "td") and mode in ("edge_tts", "mms_tts"):
        return int(os.getenv("MMS_TTS_STAGGER_SECONDS", "60"))
    return 0


def _get_orchestration_mode() -> str:
    """Read orchestration mode from Redis (written by the admin toggle). Defaults to 'pipeline'."""
    try:
        import redis as _redis
        client = _redis.from_url(os.getenv("REDIS_URL", "redis://localhost:6379/0"))
        raw = client.get("ai:orchestration_mode")
        if isinstance(raw, bytes):
            raw = raw.decode()
        return raw if raw in ("pipeline", "agent") else "pipeline"
    except Exception:
        return "pipeline"


@app.task(name="tasks.orchestrate")
def orchestrate(job: dict) -> None:
    """Entry point. Routes to AI agent or hard-coded pipeline based on admin toggle."""
    mode  = _get_orchestration_mode()
    token = job["session_token"]
    print(f"[orchestrate] mode={mode} session={token[:8]}…", flush=True)

    if mode == "agent":
        try:
            import agent_orchestrator
            agent_orchestrator.run_agent(job)
            return
        except Exception as exc:  # noqa: BLE001 - keep worship services from getting stuck empty
            print(
                f"[orchestrate] agent failed for {token[:8]}..., falling back to pipeline: {exc}",
                flush=True,
            )

    _orchestrate_pipeline(job)


def _orchestrate_pipeline(job: dict) -> None:
    """Original hard-coded pipeline — runs when mode == 'pipeline'."""
    llm_engine.session_prompt_tokens.set(0)
    llm_engine.session_completion_tokens.set(0)

    token = job["session_token"]

    # 1. Derive the spine of the service from the user's own input.
    plan = llm_engine.build_intake_plan(
        user_name=job["user_name"], mood=job["mood"], prayer_text=job.get("prayer_text"),
        language=job.get("language", "en"),
        music_source=job.get("music_source"),
        user_history=job.get("user_history"),
    )

    p_tok = llm_engine.session_prompt_tokens.get()
    c_tok = llm_engine.session_completion_tokens.get()
    if p_tok > 0 or c_tok > 0:
        _post_asset(token, "telemetry_plan", asset_type="telemetry", prompt_tokens=p_tok, completion_tokens=c_tok)

    # 1b. Registered worshippers get a short, mood-aware "welcome back" greeting up
    # front so the countdown screen has something personal to show while the heavier
    # segments compose. Guests skip it — there's no real name to welcome back. Fired
    # first, on its own task, so it lands well before the prayer/sermon.
    if job.get("is_registered"):
        generate_welcome.delay(job)

    # 2. Fan out. These run on their named queues in parallel.
    # MusicGen on CPU takes 15-25 min and exhausts RAM. If the music queue already
    # has a task waiting, fall back to a hymn so new services don't wait indefinitely.
    if job.get("music_source") == "musicgen" and _musicgen_queue_depth() >= 1:
        print(
            f"[orchestrate] MusicGen queue backed up, falling back to hymn for {token}",
            flush=True,
        )
        job = {**job, "music_source": "hymn"}
    generate_text_segments.delay(job, plan)
    generate_music.delay(job, plan)


@app.task(name="tasks.generate_welcome")
def generate_welcome(job: dict) -> None:
    """Short mood-aware greeting for the countdown screen (registered users only)."""
    text = llm_engine.generate_welcome(user_name=job["user_name"], mood=job["mood"],
                                       language=job.get("language", "en"))
    _post_asset(job["session_token"], "welcome", asset_type="text", text_payload=text)


@app.task(name="tasks.generate_text_segments")
def generate_text_segments(job: dict, plan: dict) -> None:
    llm_engine.session_prompt_tokens.set(0)
    llm_engine.session_completion_tokens.set(0)

    token, name, mood = job["session_token"], job["user_name"], job["mood"]
    ref = plan["scripture_ref"]
    language = job.get("language", "en")  # 'en' | 'my' | 'td' — the whole service's language

    # Synthesize TTS audio only when the admin chose a server voice provider —
    # 'openai', 'kokoro', 'edge_tts', 'mms_tts', or 'voicebox'. In 'browser'/'off'
    # the client handles (or skips) reading aloud, so we deliver text only.
    narration_mode = job.get("narration_mode")
    voicebox_engine = job.get("voicebox_engine", "qwen")
    gender = job.get("presenter_gender", "female")
    want_audio = _wants_server_narration(job)
    def _seg_gender(segment: str) -> str:
        return _segment_gender(segment, gender)

    def _narrate_voice(g: str) -> str:
        return _narration_voice(narration_mode, voicebox_engine, language, g)

    # Scripture: model picks the reference, the bundled public-domain Bible supplies
    # the words. If resolution fails (unparseable/missing reference), still give the
    # listener the reference rather than aborting the whole text segment.
    try:
        scripture_text = bible_api.resolve(ref, lang=language)
        # Caption non-English scripture with the translation's own book name
        # ('John 3:16' -> 'ရှင်ယောဟန်ခရစ်ဝင် 3:16' for 'my', 'Johan 3:16' for
        # 'td') so no English leaks into the worshipper-facing text.
        heading = (bible_api.book_title(ref, lang=language) or ref) if language != "en" else ref
        scripture_payload = f"{heading}\n\n{scripture_text}"
    except Exception as exc:  # noqa: BLE001 — degrade gracefully, never block the service
        print(f"[scripture] resolve failed for {ref!r}: {exc}", flush=True)
        scripture_text = ""
        scripture_payload = ref
    _post_asset(token, "scripture", asset_type="text", text_payload=scripture_payload)
    # Read the passage aloud (the verse words, not the bare reference) when narration
    # is configured. Scripture gets a voice but no avatar — it's shown as written.
    # Stagger providers that are sensitive to overlap: Kokoro can rate-limit, and
    # Myanmar/Tedim local MMS-TTS is CPU-heavy on the small server.
    _narrate_stagger = _narration_stagger(narration_mode, language)
    _narrate_slot = 0
    # Scripture narration is queued *after* the opening prayer below. The frontend
    # opens the doors the moment opening_prayer audio lands, so the prayer must take
    # the first (slot 0) TTS slot — narrating scripture first would push the prayer a
    # full stagger interval (up to 60 s for CPU MMS-TTS) behind, stranding worshippers
    # on the preparing screen. Scripture still plays before the prayer in the service
    # order; its audio simply attaches a slot later.

    # In YouTube mode the preaching message is an existing sermon video found on
    # YouTube (mood-based), not an AI-written sermon — this saves the LLM call plus
    # any avatar/TTS for the service's longest segment. Best-effort, like music: if
    # the search fails we skip the message rather than fall back to generating one.
    user_history = job.get("user_history")
    prayer_text = job.get("prayer_text")
    youtube_mode = job.get("music_source") == "youtube"
    # A special Sunday in 'manual' sermon mode supplies a hand-authored sermon
    # that is spoken verbatim — it replaces both the AI sermon and the YouTube
    # sermon-video lookup for this language.
    manual_sermon = _special_sunday_content(job, "sermon")
    if youtube_mode and not manual_sermon:
        try:
            past_video_ids = (user_history or {}).get("past_video_ids", [])
            # A special Sunday biases the YouTube sermon search toward the
            # observance (its moods/title) on top of the worshipper's keywords.
            preaching_query = _special_sunday_music_query(
                job, plan.get("preaching_query", ""))
            video = find_sermon_video(mood=mood, query=preaching_query,
                                      language=language,
                                      excluded_ids=past_video_ids)
            _post_asset(token, "sermon", asset_type="youtube",
                        provider_ref=video["video_id"], text_payload=video["title"])
        except Exception as exc:  # noqa: BLE001 — degrade gracefully, never block
            print(f"[sermon] youtube lookup failed for mood {mood!r}: {exc}", flush=True)

    def _process_spoken_segment(segment_name, text):
        nonlocal _narrate_slot
        try:
            ok, reason = classifier.review(text)
        except Exception as exc:  # noqa: BLE001 — classifier error must not kill the segment
            print(f"[classifier] review failed for {segment_name}: {exc}", flush=True)
            ok = True
        if not ok:
            _post_asset(token, segment_name, asset_type="text",
                        text_payload="(content withheld pending review)")
            return
        _post_asset(token, segment_name, asset_type="text", text_payload=text)
        # Optionally enrich the segment with a talking-head video. Only the spoken
        # segments get an avatar; scripture is shown as written, not performed.
        seg_gender = _seg_gender(segment_name)
        _avatar_engine = avatar.select_engine(
            did_enabled=job.get("avatar_enabled", True),
            local_enabled=job.get("local_avatar_enabled", False),
        )
        if _avatar_engine:
            render_avatar.delay(token, segment_name, text, seg_gender, _avatar_engine)
        if want_audio:
            args = (token, segment_name, text, narration_mode, _narrate_voice(seg_gender), seg_gender, language)
            narrate.apply_async(args, countdown=_narrate_slot)
            _narrate_slot += _narrate_stagger

    # Generate sequentially so frontend can open as soon as opening_prayer lands.
    # The prayer is narrated first (slot 0) so its audio — the door's gating asset —
    # is rendered ahead of everything else.
    op_text = llm_engine.generate_opening_prayer(
        user_name=name, mood=mood, prayer_text=prayer_text, language=language,
        user_history=user_history)
    _process_spoken_segment("opening_prayer", op_text)

    # Scripture audio comes next, now that the prayer has claimed the first TTS slot.
    if want_audio:
        _sg = _seg_gender("scripture")
        narrate.apply_async((token, "scripture", scripture_text or ref, narration_mode, _narrate_voice(_sg), _sg, language),
                            countdown=_narrate_slot)
        _narrate_slot += _narrate_stagger

    if manual_sermon and (manual_sermon.get("body") or "").strip():
        # Curated special-Sunday sermon — spoken as written (still reviewed,
        # narrated, and avatar-rendered like any other spoken segment).
        _process_spoken_segment("sermon", manual_sermon["body"].strip())
    elif not youtube_mode:
        sermon_minutes = 5 if job.get("music_source") == "musicgen" else 8
        sermon_text = llm_engine.generate_sermon(
            user_name=name, mood=mood, scripture_ref=ref, language=language,
            target_minutes=sermon_minutes, prayer_text=prayer_text,
            user_history=user_history, theme=_special_sunday_theme(job))
        _process_spoken_segment("sermon", sermon_text)

    ben_text = llm_engine.generate_benediction(
        user_name=name, mood=mood, language=language,
        prayer_text=prayer_text, user_history=user_history)
    _process_spoken_segment("benediction", ben_text)

    p_tok = llm_engine.session_prompt_tokens.get()
    c_tok = llm_engine.session_completion_tokens.get()
    if p_tok > 0 or c_tok > 0:
        _post_asset(token, "telemetry_segments", asset_type="telemetry", prompt_tokens=p_tok, completion_tokens=c_tok)


@app.task(name="tasks.repair_missing_narration")
def repair_missing_narration(job: dict) -> None:
    """Backfill narration for completed text segments that are missing audio.

    Laravel enqueues this from the poll endpoint when it sees a completed service
    with text but no audio_key. The work is idempotent at the webhook level because
    narration only enriches the existing service_assets row.
    """
    if not _wants_server_narration(job):
        return

    token = job["session_token"]
    language = job.get("language", "en")
    mode = job.get("narration_mode", "openai")
    voicebox_engine = job.get("voicebox_engine", "qwen")
    presenter_gender = job.get("presenter_gender", "female")
    storage.set_backend(job.get("storage_backend"))

    countdown = 0
    stagger = _narration_stagger(mode, language)
    queued = 0
    for item in job.get("segments", []):
        if not item or not isinstance(item, dict):
            continue

        segment = item.get("segment")
        text = (item.get("text") or "").strip()
        if segment not in NARRATED_SEGMENTS or not text:
            continue

        gender = _segment_gender(segment, presenter_gender)
        narrate.apply_async(
            (token, segment, text, mode, _narration_voice(mode, voicebox_engine, language, gender), gender, language),
            countdown=countdown,
        )
        countdown += stagger
        queued += 1

    print(f"[narration-repair] queued {queued} segment(s) for {token[:8]}…", flush=True)


@app.task(name="tasks.generate_music", time_limit=1800, soft_time_limit=1500, reject_on_worker_lost=True)
def generate_music(job: dict, plan: dict) -> None:
    """Resolves Suno vs YouTube from the session's locked preference, honoring the
    admin storage backend and the mood-keyed reuse pool.

    time_limit=1800 (30 min hard): kills a runaway MusicGen that would otherwise
    block the music queue forever (e.g. model thrashing swap on a busy box).
    soft_time_limit=1500 (25 min): raises SoftTimeLimitExceeded so the task can
    release the Redis lock before the hard kill arrives."""
    # Where generated audio lands (local dir vs S3) is an admin setting Laravel threads
    # through; None falls back to the worker's env default.
    storage.set_backend(job.get("storage_backend"))

    # A special Sunday in 'manual' music mode pins a specific curated song. Serve it
    # and skip the mood-selection path entirely; on any failure, fall through to the
    # normal worship so the service is never left silent.
    manual_song = _special_sunday_content(job, "music")
    if manual_song and _deliver_manual_song(job, manual_song):
        print(f"[music] served manual special-Sunday song for {job['session_token']}", flush=True)
        return

    reuse = job.get("reuse_track")
    if reuse:
        # Laravel chose to reuse a song another worshipper already composed for this
        # mood. Re-presign its stored key so the URL is freshly valid (S3 presigns
        # expire; local URLs are stable). No Suno call, no new pool entry.
        result = MusicResult(
            asset_type="audio",
            storage_key=storage.presign(reuse["storage_key"], expires=6 * 3600),
            provider_ref=reuse.get("provider_ref"),
            title=reuse.get("title"),
            lyrics=reuse.get("lyrics"),
        )
    else:
        strategy = get_strategy(job["music_source"], language=job.get("language", "en"))
        # Music depends on external providers (YouTube/Suno + S3 upload). If their
        # keys are missing or the call fails, skip music rather than crash the task.
        music_prompt = plan.get("music_prompt", "")
        if job["music_source"] in ("suno", "musicgen"):
            language = job.get("language", "en")
            if language in ("td", "my"):
                # For non-English services, delegate lyric generation to the local
                # language-specific LLM (Tedim :8001 / Burmese :8002). OpenRouter
                # English-first models cannot reliably produce correct Zolai or Myanmar.
                # generate_music_lyrics falls back to curated hardcoded lyrics if the
                # local service is down or the output fails the language guard.
                music_lyrics = llm_engine.generate_music_lyrics(mood=job["mood"], language=language)
            else:
                music_lyrics = str(plan.get("music_lyrics") or "")
                if not llm_engine._lyrics_match_language(music_lyrics, language):
                    music_lyrics = llm_engine._fallback_music_lyrics(job["mood"], language)
            if language == "td":
                music_prompt = (
                    f"Modern contemporary Zomi/Tedim Christian worship song for someone feeling {job['mood']}, "
                    "in a live contemporary worship style with uplifting congregational energy, "
                    "with warm choir and acoustic-band arrangement. Sing the provided Tedim lyrics exactly."
                )
            elif language == "my":
                music_prompt = (
                    f"Modern contemporary Burmese Christian worship song for someone feeling {job['mood']}, "
                    "in a live contemporary worship style with uplifting congregational energy, "
                    "with warm choir and acoustic-band arrangement. Sing the provided Myanmar Unicode lyrics exactly."
                )
            else:
                music_prompt = (
                    f"Modern contemporary Christian worship song for someone feeling {job['mood']}, "
                    "in a live contemporary worship style with uplifting congregational energy, "
                    "with warm choir and acoustic-band arrangement. Sing the provided lyrics exactly."
                )
            music_prompt = f"{music_prompt}\n\nLyrics:\n{music_lyrics}"

        result = None
        used_local_fallback = False
        max_attempts = 3
        for attempt in range(1, max_attempts + 1):
            try:
                result = strategy.fetch(
                    mood=job["mood"],
                    prompt=music_prompt,
                    # A special Sunday folds its music_moods into the hymn query so
                    # worship leans toward the observance (e.g. Easter → joyful).
                    query=_special_sunday_music_query(job, plan.get("music_query", "")),
                )
                break
            except Exception as exc:  # noqa: BLE001 — degrade gracefully
                print(
                    f"[music] {job['music_source']} fetch failed "
                    f"(attempt {attempt}/{max_attempts}): {exc}",
                    flush=True,
                )
                if attempt < max_attempts:
                    time.sleep(2 if attempt == 1 else 6)

        # Delivery guarantee: if the chosen source (Suno/YouTube/MusicGen/Local AI)
        # exhausted its retries, fall back to a local hymn before giving up. The
        # local hymn library is always present on disk, so this serves real worship
        # instead of an apology in the overwhelming majority of failures. Only if
        # even the local read fails do we degrade to the text notice below.
        if result is None and job["music_source"] not in ("hymn_sung", "hymn"):
            for fb_source in ("hymn_sung", "hymn"):
                try:
                    fb_strategy = get_strategy(fb_source, language=job.get("language", "en"))
                    result = fb_strategy.fetch(
                        mood=job["mood"],
                        prompt="",
                        query=_special_sunday_music_query(job, plan.get("music_query", "")),
                    )
                    used_local_fallback = True
                    print(
                        f"[music] {job['music_source']} unavailable — "
                        f"falling back to local {fb_source}",
                        flush=True,
                    )
                    break
                except Exception as exc:  # noqa: BLE001 — try the next local source
                    print(f"[music] local {fb_source} fallback failed: {exc}", flush=True)

        if result is None:
            language = job.get("language", "en")
            if language == "td":
                fallback_notice = "La sa ding ngah thei nailo hi. Thu leh thungetna tawh kizom in i pan ding hi."
            elif language == "my":
                fallback_notice = "ယခုအချိန်တွင် သီချင်းဖွင့်မရသေးပါ။ ကျမ်းစာနှင့် ဆုတောင်းဖြင့် ဆက်လက်ဝတ်ပြုပါမည်။"
            else:
                fallback_notice = "Worship music is unavailable right now. We will continue with scripture and prayer."
            for segment in ("worship", "closing_hymn"):
                _post_asset(job["session_token"], segment, asset_type="text", text_payload=fallback_notice)
            return

        # Suno/hymn hand back a RAW object key: presign it for the browser. A fresh
        # Suno track also joins the reusable mood pool so other worshippers can be
        # served it. YouTube carries no stored file (storage_key is None) — embedded,
        # never pooled. Hymns come from a fixed local library (HymnStrategy): presign
        # for playback, but never pool — there's nothing to save by reusing, and
        # pooling would pin one hymn per mood and defeat the strategy's variety.
        if result.asset_type == "audio" and result.storage_key:
            raw_key = result.storage_key
            result.storage_key = storage.presign(raw_key, expires=6 * 3600)
            # Never pool a local hymn we only reached via the fallback path — the
            # reuse pool is for AI-composed tracks, and a hymn key there would be
            # served to other Suno/MusicGen worshippers as if it were generated.
            if not used_local_fallback and job["music_source"] in ("suno", "musicgen"):
                _post_music_track(
                    mood=job["mood"], language=job.get("language", "en"), provider_ref=result.provider_ref,
                    storage_key=raw_key, title=result.title, lyrics=result.lyrics,
                )

    for segment in ("worship", "closing_hymn"):
        _post_asset(
            job["session_token"], segment,
            asset_type=result.asset_type,
            storage_key=result.storage_key,
            provider_ref=result.provider_ref,
            # Caption the track in the player (hymn name + performer/author; "Worship
            # (mood)" for Suno), carried in text_payload. For hymn sources, the
            # public-domain verses ride along in `lyrics` for on-screen display.
            text_payload=result.title,
            lyrics=result.lyrics,
            # LRC line timings for synced on-screen lyrics (static sung hymns);
            # None for dynamic sources, so the player keeps plain verses.
            timings=result.timings,
        )


def _fetch_narration_audio(session_token: str, segment: str) -> str | None:
    """Pull the narration audio for `segment` back out of storage and write it to a temp
    file so the local avatar API can lip-sync to it. Returns the temp path, or None if the
    narration hasn't landed in storage yet (it runs as a separate, parallel task)."""
    matches = [
        k for k in storage.list_keys(f"narration/{session_token}/")
        if os.path.splitext(os.path.basename(k))[0] == segment
    ]
    if not matches:
        return None
    key = matches[0]
    data = storage.read_bytes(key)
    if not data:
        return None
    suffix = os.path.splitext(key)[1] or ".wav"
    fd, path = tempfile.mkstemp(prefix=f"avatar_{segment}_", suffix=suffix)
    with os.fdopen(fd, "wb") as f:
        f.write(data)
    return path


@app.task(name="tasks.render_avatar", bind=True, max_retries=12, default_retry_delay=20)
def render_avatar(self, session_token: str, segment: str, script: str, gender: str = "female",
                  engine: str = "did") -> None:
    """Render `script` as a talking-head video and post it back as the segment's video
    asset. The text asset was already delivered, so any failure here just leaves the
    segment as text — never block or crash the service.

    `engine` ("did" | "local") is resolved upstream from the admin toggles. The D-ID path
    synthesizes speech itself from `script`; the local open-source path needs the narration
    audio to lip-sync to, produced by the separate `narrate` task, so we fetch it from
    storage and retry while it's pending."""
    if not avatar.is_enabled():
        return

    audio_path = None
    if engine == "local":
        audio_path = _fetch_narration_audio(session_token, segment)
        if audio_path is None:
            # Narration not in storage yet — wait for the parallel narrate task to finish.
            try:
                raise self.retry(countdown=20)
            except self.MaxRetriesExceededError:
                print(f"[avatar] narration audio never landed for {segment} "
                      f"({session_token[:8]}…); leaving as text", flush=True)
                return

    try:
        video_url = avatar.render(session_token, segment, script, gender=gender,
                                  audio_path=audio_path, engine=engine)
    except Exception as exc:  # noqa: BLE001 — transient RunPod blips (throttling, cold
        # worker, gateway timeout) are common; retry a few times before giving up so a
        # single failure doesn't permanently drop the segment's avatar.
        print(f"[avatar] render failed for {segment} ({session_token[:8]}…): {exc}", flush=True)
        try:
            raise self.retry(countdown=15, exc=exc)
        except self.MaxRetriesExceededError:
            print(f"[avatar] giving up on {segment} after retries; leaving as text", flush=True)
            return
    finally:
        if audio_path and os.path.exists(audio_path):
            os.remove(audio_path)
    if not video_url:
        print(f"[avatar] render returned no URL for {segment} ({session_token[:8]}…); leaving as text", flush=True)
        return
    _post_asset(session_token, segment, asset_type="video", storage_key=video_url)


@app.task(name="tasks.narrate", bind=True, max_retries=4, default_retry_delay=30, time_limit=900, soft_time_limit=840)
def narrate(
    self,
    session_token: str,
    segment: str,
    script: str,
    mode: str = "openai",
    voice: str = "",
    gender: str = "female",
    language: str = "en",
) -> None:
    """Read `script` aloud with the `mode` voice provider and post it back as the
    segment's audio narration. The text asset was already delivered, so any failure
    here just leaves the segment without audio — never block or crash the service."""
    import requests as _req
    from celery.exceptions import SoftTimeLimitExceeded

    if not narrator.is_enabled(mode):
        return
    try:
        audio_url = narrator.narrate(
            session_token, segment, script, mode, voice=voice, gender=gender, language=language
        )
    except SoftTimeLimitExceeded as exc:
        print(f"[narrator] tts soft time limit exceeded for {segment}: {exc}", flush=True)
        _post_asset(session_token, segment, audio_key="failed")
        return
    except _req.exceptions.HTTPError as exc:
        if exc.response is not None and exc.response.status_code == 429:
            if self.request.retries >= self.max_retries:
                print(f"[narrator] tts max retries exceeded for {segment} (429)", flush=True)
                _post_asset(session_token, segment, audio_key="failed")
                return
            raise self.retry(exc=exc, countdown=30 * (2 ** self.request.retries))
        print(f"[narrator] tts failed for {segment} ({session_token[:8]}…): {exc}", flush=True)
        _post_asset(session_token, segment, audio_key="failed")
        return
    except (_req.exceptions.ReadTimeout, _req.exceptions.ConnectionError) as exc:
        if self.request.retries >= self.max_retries:
            print(f"[narrator] tts max retries exceeded for {segment} (timeout)", flush=True)
            _post_asset(session_token, segment, audio_key="failed")
            return
        raise self.retry(exc=exc, countdown=30 * (2 ** self.request.retries))
    except Exception as exc:  # noqa: BLE001 — degrade gracefully to the silent segment
        print(f"[narrator] tts failed for {segment} ({session_token[:8]}…): {exc}", flush=True)
        _post_asset(session_token, segment, audio_key="failed")
        return

    # Enrich the existing segment row with its narration; the webhook keeps the
    # segment's text (and any avatar video) intact and just fills in audio_key.
    _post_asset(session_token, segment, audio_key=audio_url)


@app.task(name="tasks.generate_bible_bg", time_limit=1800, soft_time_limit=1500)
def generate_bible_bg(theme: str, tod: str, engine: str = "musicgen", storage_backend: str = "") -> None:
    """Generate (once) the AI background-music loop for a Bible (theme, tod) bucket.

    Offloaded from the Bible API process so MusicGen never blocks narration. The
    deterministic storage key makes this idempotent: if the track already exists
    (another reader triggered it first) the task is a cheap no-op.
    """
    import bible_bg  # noqa: PLC0415 — heavy deps, import lazily on the worker

    if storage_backend:
        storage.set_backend(storage_backend)
    try:
        url = bible_bg.generate_track(theme, tod, engine=engine)
        print(f"[bible-bg] ready {theme}/{tod}: {url}", flush=True)
    except Exception as exc:  # noqa: BLE001 — best-effort; reader falls back to silence
        print(f"[bible-bg] generation failed for {theme}/{tod}: {exc}", flush=True)
