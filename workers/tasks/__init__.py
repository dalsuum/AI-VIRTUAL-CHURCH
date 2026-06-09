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
from strategies import get_strategy  # noqa: E402
from tasks.celery_app import app  # noqa: E402

LARAVEL_WEBHOOK = os.environ["LARAVEL_WEBHOOK_URL"]  # e.g. https://api.host/api/internal/asset-ready
WORKER_SECRET = os.environ["WORKER_WEBHOOK_SECRET"]


def _post_asset(session_token: str, segment: str, **fields) -> None:
    payload = {"session_token": session_token, "segment": segment, **fields}
    requests.post(
        LARAVEL_WEBHOOK,
        json=payload,
        headers={"X-Worker-Secret": WORKER_SECRET},
        timeout=30,
    ).raise_for_status()


@app.task(name="tasks.orchestrate")
def orchestrate(job: dict) -> None:
    """Entry point. `job` is the JSON pushed by Laravel's DispatchServiceJob."""
    token = job["session_token"]

    # 1. Derive the spine of the service from the user's own input.
    plan = llm_engine.build_intake_plan(
        user_name=job["user_name"], mood=job["mood"], prayer_text=job.get("prayer_text"),
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
    text = llm_engine.generate_welcome(user_name=job["user_name"], mood=job["mood"])
    _post_asset(job["session_token"], "welcome", asset_type="text", text_payload=text)


@app.task(name="tasks.generate_text_segments")
def generate_text_segments(job: dict, plan: dict) -> None:
    token, name, mood = job["session_token"], job["user_name"], job["mood"]
    ref = plan["scripture_ref"]

    # Scripture: model picks the reference, the bundled public-domain Bible supplies
    # the words. If resolution fails (unparseable/missing reference), still give the
    # listener the reference rather than aborting the whole text segment.
    try:
        scripture_text = bible_api.resolve(ref)
        scripture_payload = f"{ref}\n\n{scripture_text}"
    except Exception as exc:  # noqa: BLE001 — degrade gracefully, never block the service
        print(f"[scripture] resolve failed for {ref!r}: {exc}", flush=True)
        scripture_text = ""
        scripture_payload = ref
    _post_asset(token, "scripture", asset_type="text", text_payload=scripture_payload)
    # Read the passage aloud (the verse words, not the bare reference) when narration
    # is configured. Scripture gets a voice but no avatar — it's shown as written.
    if narrator.is_enabled():
        narrate.delay(token, "scripture", scripture_text or ref)

    for segment, text in (
        ("opening_prayer", llm_engine.generate_opening_prayer(
            user_name=name, mood=mood, prayer_text=job.get("prayer_text"))),
        ("sermon", llm_engine.generate_sermon(
            user_name=name, mood=mood, scripture_ref=ref)),
        ("benediction", llm_engine.generate_benediction(user_name=name, mood=mood)),
    ):
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
        if narrator.is_enabled():
            narrate.delay(token, segment, text)


@app.task(name="tasks.generate_music")
def generate_music(job: dict, plan: dict) -> None:
    """Resolves Suno vs YouTube from the session's locked preference."""
    strategy = get_strategy(job["music_source"])
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

    for segment in ("worship", "closing_hymn"):
        _post_asset(
            job["session_token"], segment,
            asset_type=result.asset_type,
            storage_key=result.storage_key,
            provider_ref=result.provider_ref,
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


@app.task(name="tasks.narrate")
def narrate(session_token: str, segment: str, script: str) -> None:
    """Read `script` aloud and post it back as the segment's audio narration. The
    text asset was already delivered, so any failure here just leaves the segment
    without audio — never block or crash the service."""
    if not narrator.is_enabled():
        return
    try:
        audio_url = narrator.narrate(session_token, segment, script)
    except Exception as exc:  # noqa: BLE001 — degrade gracefully to the silent segment
        print(f"[narrator] tts failed for {segment} ({session_token[:8]}…): {exc}", flush=True)
        return
    # Enrich the existing segment row with its narration; the webhook keeps the
    # segment's text (and any avatar video) intact and just fills in audio_key.
    _post_asset(session_token, segment, audio_key=audio_url)
