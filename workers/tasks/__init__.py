"""
Celery tasks. The orchestrator fans intake out into parallel generation tasks; each
task posts its finished asset back to Laravel's /internal/asset-ready webhook, which
in turn pushes it to the client over WebSockets.
"""

from __future__ import annotations

import os
import sys
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


@app.task(name="tasks.orchestrate")
def orchestrate(job: dict) -> None:
    """Entry point. `job` is the JSON pushed by Laravel's DispatchServiceJob."""
    token = job["session_token"]

    # 1. Derive the spine of the service from the user's own input.
    plan = llm_engine.build_intake_plan(
        user_name=job["user_name"], mood=job["mood"], prayer_text=job.get("prayer_text"),
        language=job.get("language", "en"),
        music_source=job.get("music_source"),
        user_history=job.get("user_history"),
    )

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
    token, name, mood = job["session_token"], job["user_name"], job["mood"]
    ref = plan["scripture_ref"]
    language = job.get("language", "en")  # 'en' | 'my' | 'td' — the whole service's language

    # Synthesize TTS audio only when the admin chose a server voice provider —
    # 'openai', 'kokoro', or 'edge_tts' — server generates audio. In 'browser'/'off'
    # the client handles (or skips) reading aloud, so we deliver text only.
    narration_mode = job.get("narration_mode")
    voicebox_engine = job.get("voicebox_engine", "kokoro")
    gender = job.get("presenter_gender", "female")
    # narration_mode is now set per-language by the admin dashboard.
    # A False value suppresses server TTS for this service entirely.
    narration_enabled = job.get("narration_enabled", True)
    want_audio = (
        narration_enabled
        and narration_mode in ("openai", "kokoro", "edge_tts", "mms_tts", "voicebox")
        and narrator.is_enabled(narration_mode)
    )
    def _seg_gender(segment: str) -> str:
        """Sermon uses the chosen presenter gender; all other segments use the opposite
        so the preacher and the support voice are always a matched pair."""
        return gender if segment == "sermon" else ("female" if gender == "male" else "male")

    def _edge_voice(g: str) -> str:
        import os as _os
        suffix = g.upper()
        if language == "my":
            # Myanmar has two native Edge TTS voices; pick by gender.
            default = "my-MM-ThihaNeural" if g == "male" else "my-MM-NilarNeural"
            return (_os.getenv(f"EDGE_TTS_VOICE_MY_{suffix}")
                    or _os.getenv("EDGE_TTS_VOICE_MY", default))
        if language == "td":
            # No native Zolai Edge TTS voice; use a configurable fallback.
            # Tedim is Latin-script so an English voice reads it phonetically.
            default = "en-US-GuyNeural" if g == "male" else "en-US-AriaNeural"
            return _os.getenv("EDGE_TTS_VOICE_TD", default)
        default = "en-US-GuyNeural" if g == "male" else "en-US-AriaNeural"
        return _os.getenv(f"EDGE_TTS_VOICE_{suffix}") or _os.getenv("EDGE_TTS_VOICE", default)

    def _narrate_voice(g: str) -> str:
        """Return the voice/engine param for the narrate task based on mode.
        For voicebox mode this carries the engine name; for edge_tts it carries
        the Edge TTS voice name; for other modes the value is unused."""
        if narration_mode == "voicebox":
            return voicebox_engine
        return _edge_voice(g)

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
    # OpenRouter (kokoro) has tight per-minute rate limits; stagger narration calls
    # 45 s apart so concurrent segments don't overlap and trigger provider 429s.
    # Myanmar/Tedim edge_tts mode is backed by local MMS-TTS; stagger those CPU-heavy
    # requests too so the ARM box does not get several long VITS generations at once.
    if narration_mode == "kokoro":
        _narrate_stagger = 45
    elif language in ("my", "td") and narration_mode == "edge_tts":
        # MMS-TTS already serializes via its own asyncio.Semaphore(1), so
        # a small stagger is enough to avoid hammering Celery's queue.
        _narrate_stagger = int(os.getenv("MMS_TTS_STAGGER_SECONDS", "5"))
    else:
        _narrate_stagger = 0
    _narrate_slot = 0

    if want_audio:
        _sg = _seg_gender("scripture")
        narrate.apply_async((token, "scripture", scripture_text or ref, narration_mode, _narrate_voice(_sg), _sg, language),
                            countdown=_narrate_slot)
        _narrate_slot += _narrate_stagger

    # In YouTube mode the preaching message is an existing sermon video found on
    # YouTube (mood-based), not an AI-written sermon — this saves the LLM call plus
    # any avatar/TTS for the service's longest segment. Best-effort, like music: if
    # the search fails we skip the message rather than fall back to generating one.
    user_history = job.get("user_history")
    prayer_text = job.get("prayer_text")
    youtube_mode = job.get("music_source") == "youtube"
    if youtube_mode:
        try:
            past_video_ids = (user_history or {}).get("past_video_ids", [])
            video = find_sermon_video(mood=mood, query=plan.get("preaching_query", ""),
                                      language=language,
                                      excluded_ids=past_video_ids)
            _post_asset(token, "sermon", asset_type="youtube",
                        provider_ref=video["video_id"], text_payload=video["title"])
        except Exception as exc:  # noqa: BLE001 — degrade gracefully, never block
            print(f"[sermon] youtube lookup failed for mood {mood!r}: {exc}", flush=True)
    spoken = [
        ("opening_prayer", llm_engine.generate_opening_prayer(
            user_name=name, mood=mood, prayer_text=prayer_text, language=language,
            user_history=user_history)),
        ("benediction", llm_engine.generate_benediction(
            user_name=name, mood=mood, language=language,
            prayer_text=prayer_text, user_history=user_history)),
    ]
    # Only generate the sermon when we aren't sourcing it from YouTube.
    if not youtube_mode:
        sermon_minutes = 5 if job.get("music_source") == "musicgen" else 8
        spoken.insert(1, ("sermon", llm_engine.generate_sermon(
            user_name=name, mood=mood, scripture_ref=ref, language=language,
            target_minutes=sermon_minutes, prayer_text=prayer_text,
            user_history=user_history)))

    deferred_narrations = []
    for segment, text in spoken:
        ok, reason = classifier.review(text)
        if not ok:
            _post_asset(token, segment, asset_type="text",
                        text_payload="(content withheld pending review)")
            continue
        _post_asset(token, segment, asset_type="text", text_payload=text)
        # Optionally enrich the segment with a talking-head video. Only the spoken
        # segments get an avatar; scripture is shown as written, not performed.
        seg_gender = _seg_gender(segment)
        if job.get("avatar_enabled", True) and avatar.is_enabled():
            render_avatar.delay(token, segment, text, seg_gender)
        # Myanmar/Tedim sermon TTS is much slower than prayer/benediction on CPU.
        # Defer it so the final prayer voice can land even if the message audio is slow.
        if want_audio:
            args = (token, segment, text, narration_mode, _narrate_voice(seg_gender), seg_gender, language)
            if language in ("my", "td") and segment == "sermon":
                deferred_narrations.append(args)
            else:
                narrate.apply_async(args, countdown=_narrate_slot)
                _narrate_slot += _narrate_stagger

    for args in deferred_narrations:
        narrate.apply_async(args, countdown=_narrate_slot)
        _narrate_slot += _narrate_stagger


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
        if job["music_source"] == "suno":
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
        max_attempts = 3
        for attempt in range(1, max_attempts + 1):
            try:
                result = strategy.fetch(
                    mood=job["mood"],
                    prompt=music_prompt,
                    query=plan.get("music_query", ""),
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

        if result is None:
            language = job.get("language", "en")
            if language == "td":
                fallback_notice = "La sa ding ngah thei nailo hi. Thu leh thungetna tawh kizom in i pai ding uh."
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
            if job["music_source"] == "suno":
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
        )


@app.task(name="tasks.render_avatar")
def render_avatar(session_token: str, segment: str, script: str, gender: str = "female") -> None:
    """Render `script` as a talking-head video and post it back as the segment's video
    asset. The text asset was already delivered, so any failure here just leaves the
    segment as text — never block or crash the service."""
    if not avatar.is_enabled():
        return
    try:
        video_url = avatar.render(session_token, segment, script, gender=gender)
    except Exception as exc:  # noqa: BLE001 — degrade gracefully to the text segment
        print(f"[avatar] render failed for {segment} ({session_token[:8]}…): {exc}", flush=True)
        return
    _post_asset(session_token, segment, asset_type="video", storage_key=video_url)


@app.task(name="tasks.narrate", bind=True, max_retries=4, default_retry_delay=30)
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
    if not narrator.is_enabled(mode):
        return
    try:
        audio_url = narrator.narrate(
            session_token, segment, script, mode, voice=voice, gender=gender, language=language
        )
    except _req.exceptions.HTTPError as exc:
        if exc.response is not None and exc.response.status_code == 429:
            # Back off and retry; countdown doubles each attempt (30 s, 60 s, 120 s, 240 s).
            raise self.retry(exc=exc, countdown=30 * (2 ** self.request.retries))
        print(f"[narrator] tts failed for {segment} ({session_token[:8]}…): {exc}", flush=True)
        return
    except (_req.exceptions.ReadTimeout, _req.exceptions.ConnectionError) as exc:
        # OpenRouter sometimes hangs rather than returning 429 — treat like a rate-limit
        # and retry with the same exponential backoff so we recover automatically.
        raise self.retry(exc=exc, countdown=30 * (2 ** self.request.retries))
    except Exception as exc:  # noqa: BLE001 — degrade gracefully to the silent segment
        print(f"[narrator] tts failed for {segment} ({session_token[:8]}…): {exc}", flush=True)
        return
    # Enrich the existing segment row with its narration; the webhook keeps the
    # segment's text (and any avatar video) intact and just fills in audio_key.
    _post_asset(session_token, segment, audio_key=audio_url)
