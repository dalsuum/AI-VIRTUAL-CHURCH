"""Read-only HTTP routes for the online Bible reader.

Serves the vendored public-domain translations (en BSB, my Judson 1835,
td Tedim 1932) that bible_api.py already loads and caches in memory, so a
browser-facing reader can list books and fetch chapters without touching the
service pipeline. Laravel proxies these under /api/bible so the SPA never
talks to this internal port directly.
"""

from fastapi import APIRouter, HTTPException

import bible_api

router = APIRouter(prefix="/bible", tags=["bible"])


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
