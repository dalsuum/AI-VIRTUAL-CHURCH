"""
Celery tasks. The orchestrator fans intake out into parallel generation tasks; each
task posts its finished asset back to Laravel's /internal/asset-ready webhook, which
in turn pushes it to the client over WebSockets.
"""

from __future__ import annotations

import os
import sys

import requests

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import avatar  # noqa: E402
import bible_api  # noqa: E402
import classifier  # noqa: E402
import llm_engine  # noqa: E402
import narrator  # noqa: E402
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


def _post_music_track(*, mood: str, provider_ref: str, storage_key: str, title: str | None) -> None:
    """Register a fresh Suno track in the reusable mood pool. Best-effort: the pool is
    an optimization for *future* services, so a failure here must never break this one."""
    try:
        requests.post(
            MUSIC_TRACK_WEBHOOK,
            json={"mood": mood, "provider_ref": provider_ref, "storage_key": storage_key, "title": title},
            headers={"X-Worker-Secret": WORKER_SECRET},
            timeout=30,
        ).raise_for_status()
    except Exception as exc:  # noqa: BLE001 — pool registration is non-critical
        print(f"[music] pool registration failed for {provider_ref}: {exc}", flush=True)


@app.task(name="tasks.orchestrate")
def orchestrate(job: dict) -> None:
    """Entry point. `job` is the JSON pushed by Laravel's DispatchServiceJob."""
    token = job["session_token"]

    # 1. Derive the spine of the service from the user's own input.
    plan = llm_engine.build_intake_plan(
        user_name=job["user_name"], mood=job["mood"], prayer_text=job.get("prayer_text"),
        language=job.get("language", "en"),
    )

    # 1b. Registered worshippers get a short, mood-aware "welcome back" greeting up
    # front so the countdown screen has something personal to show while the heavier
    # segments compose. Guests skip it — there's no real name to welcome back. Fired
    # first, on its own task, so it lands well before the prayer/sermon.
    if job.get("is_registered"):
        generate_welcome.delay(job)

    # 2. Fan out. These run on their named queues in parallel.
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
    language = job.get("language", "en")  # 'en' | 'my' — the whole service's language

    # Synthesize TTS audio only when the admin chose a server voice provider —
    # 'openai', 'kokoro', or 'edge_tts' — server generates audio. In 'browser'/'off'
    # the client handles (or skips) reading aloud, so we deliver text only.
    narration_mode = job.get("narration_mode")
    narration_voice = job.get("edge_tts_voice", "en-US-AriaNeural")
    # Narration must be voiced in the service's language. 'my': when the
    # configured edge-tts voice isn't a my-MM one (the admin default is
    # English), substitute the Burmese neural voice. 'td': edge-tts ships NO
    # Tedim voice — narrating Tedim text with an English voice would be
    # gibberish, so server TTS is skipped entirely unless the admin points
    # EDGE_TTS_VOICE_TD at a voice they've judged acceptable. The text segments
    # still display; the service runs read-along.
    if language == "my" and not narration_voice.startswith("my-"):
        narration_voice = os.getenv("EDGE_TTS_VOICE_MY", "my-MM-NilarNeural")
    td_voice = os.getenv("EDGE_TTS_VOICE_TD", "")
    if language == "td":
        narration_voice = td_voice
    want_audio = narration_mode in ("openai", "kokoro", "edge_tts") and narrator.is_enabled(narration_mode)
    if language == "td" and not td_voice:
        want_audio = False  # no Tedim TTS voice exists; read-along service

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
    # edge_tts and openai have no meaningful rate limit, so no stagger needed.
    _narrate_stagger = 45 if narration_mode == "kokoro" else 0
    _narrate_slot = 0

    if want_audio:
        narrate.apply_async((token, "scripture", scripture_text or ref, narration_mode, narration_voice),
                            countdown=_narrate_slot)
        _narrate_slot += _narrate_stagger

    # In YouTube mode the preaching message is an existing sermon video found on
    # YouTube (mood-based), not an AI-written sermon — this saves the LLM call plus
    # any avatar/TTS for the service's longest segment. Best-effort, like music: if
    # the search fails we skip the message rather than fall back to generating one.
    youtube_mode = job.get("music_source") == "youtube"
    if youtube_mode:
        try:
            video = find_sermon_video(mood=mood, query=plan.get("preaching_query", ""))
            _post_asset(token, "sermon", asset_type="youtube",
                        provider_ref=video["video_id"], text_payload=video["title"])
        except Exception as exc:  # noqa: BLE001 — degrade gracefully, never block
            print(f"[sermon] youtube lookup failed for mood {mood!r}: {exc}", flush=True)

    spoken = [
        ("opening_prayer", llm_engine.generate_opening_prayer(
            user_name=name, mood=mood, prayer_text=job.get("prayer_text"), language=language)),
        ("benediction", llm_engine.generate_benediction(user_name=name, mood=mood, language=language)),
    ]
    # Only generate the sermon when we aren't sourcing it from YouTube.
    if not youtube_mode:
        spoken.insert(1, ("sermon", llm_engine.generate_sermon(
            user_name=name, mood=mood, scripture_ref=ref, language=language)))

    for segment, text in spoken:
        ok, reason = classifier.review(text)
        if not ok:
            _post_asset(token, segment, asset_type="text",
                        text_payload="(content withheld pending review)")
            continue
        _post_asset(token, segment, asset_type="text", text_payload=text)
        # Optionally enrich the segment with a talking-head video. Only the spoken
        # segments get an avatar; scripture is shown as written, not performed.
        if avatar.is_enabled():
            render_avatar.delay(token, segment, text)
        # Optionally read the segment aloud. Independent of the avatar: with TTS but
        # no HeyGen the worshipper still hears the service; with both, the audio is a
        # standalone fallback to the spoken video.
        if want_audio:
            narrate.apply_async((token, segment, text, narration_mode, narration_voice),
                                countdown=_narrate_slot)
            _narrate_slot += _narrate_stagger


@app.task(name="tasks.generate_music")
def generate_music(job: dict, plan: dict) -> None:
    """Resolves Suno vs YouTube from the session's locked preference, honoring the
    admin storage backend and the mood-keyed reuse pool."""
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
        )
    else:
        strategy = get_strategy(job["music_source"], language=job.get("language", "en"))
        # Music depends on external providers (YouTube/Suno + S3 upload). If their
        # keys are missing or the call fails, skip music rather than crash the task.
        try:
            result = strategy.fetch(
                mood=job["mood"],
                prompt=plan.get("music_prompt", ""),
                query=plan.get("music_query", ""),
            )
        except Exception as exc:  # noqa: BLE001 — degrade gracefully
            print(f"[music] {job['music_source']} fetch failed: {exc}", flush=True)
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
                    mood=job["mood"], provider_ref=result.provider_ref,
                    storage_key=raw_key, title=result.title,
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
def render_avatar(session_token: str, segment: str, script: str) -> None:
    """Render `script` as a HeyGen talking-head video and post it back as the
    segment's video asset. The text asset was already delivered, so any failure
    here just leaves the segment as text — never block or crash the service."""
    if not avatar.is_enabled():
        return
    try:
        video_url = avatar.render(session_token, segment, script)
    except Exception as exc:  # noqa: BLE001 — degrade gracefully to the text segment
        print(f"[avatar] render failed for {segment} ({session_token[:8]}…): {exc}", flush=True)
        return
    _post_asset(session_token, segment, asset_type="video", storage_key=video_url)


@app.task(name="tasks.narrate", bind=True, max_retries=4, default_retry_delay=30)
def narrate(self, session_token: str, segment: str, script: str, mode: str = "openai", voice: str = "") -> None:
    """Read `script` aloud with the `mode` voice provider and post it back as the
    segment's audio narration. The text asset was already delivered, so any failure
    here just leaves the segment without audio — never block or crash the service."""
    import requests as _req
    if not narrator.is_enabled(mode):
        return
    try:
        audio_url = narrator.narrate(session_token, segment, script, mode, voice=voice)
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
