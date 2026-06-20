"""Read-only HTTP routes for the online Bible reader.

Serves the vendored public-domain translations (en BSB, kjv King James Version,
my Judson 1835, td Tedim 1932, he Hebrew WLC) that bible_api.py already loads and
caches in memory, so a
browser-facing reader can list books and fetch chapters without touching the
service pipeline. Laravel proxies these under /api/bible so the SPA never
talks to this internal port directly.
"""

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel

import bible_api
import bible_bg
import narrator
import storage

router = APIRouter(prefix="/bible", tags=["bible"])

# Narration modes that produce a stored, playable audio file (mirrors Laravel's
# Setting::SERVER_NARRATION_MODES). 'off'/'browser' never reach this worker.
_AUDIO_MODES = {"openai", "kokoro", "edge_tts", "mms_tts", "voicebox"}


def _check_lang(lang: str) -> None:
    if lang not in bible_api.languages():
        raise HTTPException(status_code=404, detail=f"Unknown translation '{lang}'")


@router.get("/languages")
async def languages():
    """Translation codes the reader can offer."""
    return {"languages": bible_api.languages()}


@router.get("/books")
async def books(lang: str = "en"):
    """Table of contents for a translation: book numbers, native names, chapter counts."""
    _check_lang(lang)
    return {"lang": lang, "books": bible_api.list_books(lang)}


@router.get("/chapter")
async def chapter(lang: str = "en", book: int = 1, chapter: int = 1):
    """One chapter's verses in the chosen translation."""
    _check_lang(lang)
    if not (1 <= book <= 66) or chapter < 1:
        raise HTTPException(status_code=422, detail="Invalid book or chapter")
    data = bible_api.chapter(lang, book, chapter)
    if not data["verses"]:
        raise HTTPException(status_code=404, detail="Chapter not found")
    return {"lang": lang, **data}


class NarrateRequest(BaseModel):
    lang: str = "en"
    book: int = 1
    chapter: int = 1
    mode: str = "edge_tts"
    gender: str = "female"
    voice: str = ""           # edge voice name or voicebox engine, resolved by Laravel
    storage_backend: str = "" # 'local' | 's3' — matches the service narration backend


@router.post("/narrate")
def narrate(req: NarrateRequest):  # sync: TTS calls block, and Edge TTS runs its own event loop
    """Read a chapter aloud (cached). Laravel resolves the provider from settings
    and proxies here; the audio is synthesized once and re-presigned thereafter."""
    _check_lang(req.lang)
    if req.mode not in _AUDIO_MODES:
        raise HTTPException(status_code=422, detail=f"Unsupported narration mode '{req.mode}'")
    if not (1 <= req.book <= 66) or req.chapter < 1:
        raise HTTPException(status_code=422, detail="Invalid book or chapter")

    data = bible_api.chapter(req.lang, req.book, req.chapter)
    if not data["verses"]:
        raise HTTPException(status_code=404, detail="Chapter not found")

    if req.storage_backend:
        storage.set_backend(req.storage_backend)

    # Read the verse text only — verse numbers would be voiced as stray numerals.
    script = " ".join(v["text"] for v in data["verses"] if v["text"])
    try:
        url = narrator.narrate_bible(
            req.lang, req.book, req.chapter, script,
            mode=req.mode, voice=req.voice, gender=req.gender,
        )
    except Exception as exc:  # noqa: BLE001 — surface a clean 502 to Laravel
        raise HTTPException(status_code=502, detail=f"Narration failed: {exc}") from exc

    return {"url": url, "name": data["name"], "chapter": data["chapter"]}


class BgMusicRequest(BaseModel):
    lang: str = "en"
    book: int = 1
    chapter: int = 1
    hour: int = 12            # reader's local hour (0-23) → time-of-day bucket
    engine: str = "musicgen"  # musicgen | local_ai
    storage_backend: str = "" # 'local' | 's3'


@router.post("/bg-music")
def bg_music(req: BgMusicRequest):
    """Resolve the AI background-music loop for a chapter + reader time-of-day.

    Returns the cached track's URL when it exists; otherwise enqueues a one-off
    MusicGen generation on the music worker and reports ``generating: true`` so
    the reader can fall back to silence (or the admin's static track) this time
    and play the AI loop on a later visit once it's cached."""
    _check_lang(req.lang)
    if not (1 <= req.book <= 66) or req.chapter < 1:
        raise HTTPException(status_code=422, detail="Invalid book or chapter")

    data = bible_api.chapter(req.lang, req.book, req.chapter)
    if not data["verses"]:
        raise HTTPException(status_code=404, detail="Chapter not found")

    if req.storage_backend:
        storage.set_backend(req.storage_backend)

    text = " ".join(v["text"] for v in data["verses"] if v["text"])
    theme = bible_bg.classify_theme(text)
    tod = bible_bg.tod_from_hour(req.hour)

    url = bible_bg.existing_url(theme, tod)
    if url:
        return {"url": url, "theme": theme, "tod": tod, "generating": False}

    engine = req.engine if req.engine in bible_bg.ENGINES else "musicgen"
    try:
        from tasks.celery_app import app as celery_app
        celery_app.send_task(
            "tasks.generate_bible_bg",
            args=[theme, tod, engine, req.storage_backend],
            queue="ai:music",
        )
    except Exception as exc:  # noqa: BLE001 — broker down: reader just gets no music
        raise HTTPException(status_code=502, detail=f"Could not queue music: {exc}") from exc

    return {"url": None, "theme": theme, "tod": tod, "generating": True}


class PregenRequest(BaseModel):
    engine: str = "musicgen"  # musicgen | local_ai
    storage_backend: str = "" # 'local' | 's3'


@router.post("/bg-music/pregenerate")
def bg_music_pregenerate(req: PregenRequest):
    """Queue generation of every uncached (theme, tod) loop so readers never wait.

    Idempotent: buckets that already exist are skipped, and each queued task
    re-checks under the shared MusicGen lock, so this is safe to click repeatedly.
    Returns how many tracks are ready and how many were queued this call."""
    if req.storage_backend:
        storage.set_backend(req.storage_backend)

    engine = req.engine if req.engine in bible_bg.ENGINES else "musicgen"

    queued = 0
    try:
        from tasks.celery_app import app as celery_app
        for theme, tod in bible_bg.all_buckets():
            if bible_bg.existing_url(theme, tod):
                continue
            celery_app.send_task(
                "tasks.generate_bible_bg",
                args=[theme, tod, engine, req.storage_backend],
                queue="ai:music",
            )
            queued += 1
    except Exception as exc:  # noqa: BLE001 — broker down
        raise HTTPException(status_code=502, detail=f"Could not queue music: {exc}") from exc

    status = bible_bg.matrix_status()
    return {"queued": queued, **status}


@router.get("/bg-music/status")
def bg_music_status(storage_backend: str = ""):
    """How many of the theme x tod matrix are generated (for the admin panel)."""
    if storage_backend:
        storage.set_backend(storage_backend)
    return bible_bg.matrix_status()
