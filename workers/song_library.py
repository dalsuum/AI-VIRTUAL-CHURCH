"""Live worship-song reader.

The `songs` table (edited in the admin Lyrics tab) is the single source of truth.
Rather than keep a JSON copy in sync, the worker reads songs on demand from the
backend's public endpoint — the same `GET /songs` the website uses. One store,
no export, no drift.

Env:
    CHURCH_API_URL  — backend API base, e.g. http://127.0.0.1:8000/api
"""

from __future__ import annotations

import functools
import os

import requests

_DEFAULT_BASE = "https://api.aivirtual.church/api"
_LANGUAGES = ("my", "td")


def _base_url() -> str:
    return os.getenv("CHURCH_API_URL", _DEFAULT_BASE).rstrip("/")


def get_songs(language: str = "my", search: str = "", timeout: float = 10.0) -> list[dict]:
    """Fetch worship songs from the library. Returns [] on any error so callers
    never crash on a transient backend hiccup.

    Each song: {id, language, title, artist, category, lyrics, has_chords, source, url}.
    """
    params: dict[str, str] = {}
    if language in _LANGUAGES:           # ignore anything outside the known enum
        params["language"] = language
    if search:
        params["search"] = search

    try:
        resp = requests.get(f"{_base_url()}/songs", params=params, timeout=timeout)
        resp.raise_for_status()
        songs = resp.json().get("songs", [])
        return songs if isinstance(songs, list) else []
    except (requests.RequestException, ValueError):
        return []


@functools.lru_cache(maxsize=4)
def _cached(language: str) -> tuple[dict, ...]:
    return tuple(get_songs(language))


def get_songs_cached(language: str = "my") -> list[dict]:
    """Process-lifetime cached read for hot paths (e.g. Suno prompt building).
    Call `refresh()` after the library changes if a worker is long-lived."""
    return list(_cached(language))


def refresh() -> None:
    """Drop the in-process cache so the next read hits the backend again."""
    _cached.cache_clear()
