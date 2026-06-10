"""Seed the public-domain hymn library used by the hymn music sources.

Run once per machine (like a database seed). It populates three things per hymn,
under the same storage backend the worker uses (local dir in dev, S3 in prod):

    hymns/<slug>.sung.mp3   a public-domain SUNG recording (Internet Archive 78rpm,
                            <=1925). Only for hymns in hymns.RECORDINGS. Used by the
                            `hymn_sung` source (real voices) — the default.
    hymns/<slug>.mp3        the hymn rendered MIDI->MP3 (instrumental). Used by the
                            `hymn` source (instrumental + on-screen lyrics).
    hymns/<slug>.txt        the hymn's public-domain lyrics (Open Hymnal), shown on
                            screen in both modes.

The SUNG recordings and LYRICS are plain downloads — no extra tools needed, so the
default `hymn_sung` mode works after a bare run. The INSTRUMENTAL render needs
fluidsynth + a soundfont + ffmpeg; if those aren't installed the seed skips just
that step (and says so) rather than failing.

    python workers/seed_hymns.py

Optional, for instrumental rendering:
    sudo apt install fluidsynth fluid-soundfont-gm ffmpeg
"""

from __future__ import annotations

import io
import os
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile
from html import unescape

import requests

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import storage  # noqa: E402
from hymns import HYMNS  # noqa: E402

MIDI_BUNDLE_URL = os.getenv(
    "OPEN_HYMNAL_MIDI_URL", "http://openhymnal.org/OpenHymnal2014.06-midi.zip"
)
LYRICS_URL = "http://openhymnal.org/Lyrics/{base}.html"

_SOUNDFONT_CANDIDATES = [
    "/usr/share/sounds/sf2/FluidR3_GM.sf2",
    "/usr/share/sounds/sf2/default-GM.sf2",
    "/usr/share/soundfonts/FluidR3_GM.sf2",
    "/usr/share/soundfonts/default.sf2",
]

# Lines that mark the end of the sung verses on an Open Hymnal lyric page.
_LYRIC_STOP = re.compile(r"^(words:|music:|setting:|translation:|source:|copyright|play pause|pdf image|gif image|midi audio|mp3 audio|abc source|open hymnal)", re.I)


def _soundfont() -> str | None:
    if shutil.which("fluidsynth") is None or shutil.which("ffmpeg") is None:
        return None
    return os.getenv("FLUID_SOUNDFONT") or next(
        (p for p in _SOUNDFONT_CANDIDATES if os.path.exists(p)), None
    )


def _render_mp3(midi_bytes: bytes, soundfont: str, tmp: str) -> bytes:
    """MIDI -> WAV (fluidsynth) -> MP3 (ffmpeg). Returns the MP3 bytes."""
    midi_path = os.path.join(tmp, "in.mid")
    wav_path = os.path.join(tmp, "out.wav")
    mp3_path = os.path.join(tmp, "out.mp3")
    with open(midi_path, "wb") as f:
        f.write(midi_bytes)
    subprocess.run(
        ["fluidsynth", "-ni", "-g", "1.0", "-r", "44100", "-F", wav_path, soundfont, midi_path],
        check=True, capture_output=True,
    )
    subprocess.run(
        ["ffmpeg", "-y", "-loglevel", "error", "-i", wav_path,
         "-codec:a", "libmp3lame", "-q:a", "4", mp3_path],
        check=True, capture_output=True,
    )
    with open(mp3_path, "rb") as f:
        return f.read()


def _fetch_lyrics(midi_base: str) -> str | None:
    """Pull the public-domain verses from the hymn's Open Hymnal lyric page."""
    try:
        r = requests.get(LYRICS_URL.format(base=midi_base), timeout=30)
        r.raise_for_status()
    except requests.RequestException:
        return None
    t = r.text
    t = re.sub(r"(?is)<(script|style|head).*?</\1>", " ", t)
    t = re.sub(r"(?is)<br\s*/?>", "\n", t)
    t = re.sub(r"(?is)</p>", "\n\n", t)
    t = re.sub(r"(?is)<[^>]+>", " ", t)
    t = unescape(t)
    lines = [re.sub(r"[ \t]+", " ", ln).strip() for ln in t.splitlines()]
    lines = [ln for ln in lines if ln]
    if not lines:
        return None
    verses = []
    for ln in lines[1:]:  # lines[0] is the hymn title, which we already have
        if _LYRIC_STOP.match(ln):
            break
        verses.append(ln)
    return "\n".join(verses).strip() or None


def main() -> None:
    soundfont = _soundfont()
    if soundfont:
        print("Downloading Open Hymnal MIDI bundle (for instrumental render) …", flush=True)
        resp = requests.get(MIDI_BUNDLE_URL, timeout=120)
        resp.raise_for_status()
        bundle = zipfile.ZipFile(io.BytesIO(resp.content))
        names = set(bundle.namelist())
    else:
        bundle = names = None
        print("! fluidsynth/ffmpeg or a soundfont not found — skipping instrumental "
              "render (the `hymn` source). Sung recordings + lyrics will still seed; "
              "install with: sudo apt install fluidsynth fluid-soundfont-gm ffmpeg", flush=True)

    counts = {"sung": 0, "instrumental": 0, "lyrics": 0}
    with tempfile.TemporaryDirectory() as tmp:
        for hymn in HYMNS:
            slug, midi = hymn["slug"], hymn["midi"]
            base = midi[:-4]  # drop ".mid"
            print(f"\n{slug}  ({hymn['title']})", flush=True)

            # 1. Sung recording (default mode) — a plain download, no tools needed.
            rec = hymn.get("recording")
            if rec:
                key = f"hymns/{slug}.sung.mp3"
                if storage.exists(key):
                    print("  · sung: already present", flush=True)
                    counts["sung"] += 1
                else:
                    try:
                        audio = requests.get(rec["url"], timeout=120).content
                        storage.upload_bytes(key, audio, "audio/mpeg")
                        counts["sung"] += 1
                        print(f"  ✓ sung: {rec['performer']} ({rec['year']}) {len(audio)//1024} KB", flush=True)
                    except Exception as exc:  # noqa: BLE001
                        print(f"  ! sung download failed: {exc}", flush=True)

            # 2. Lyrics (both modes) — a plain fetch, no tools needed.
            lkey = f"hymns/{slug}.txt"
            if storage.exists(lkey):
                counts["lyrics"] += 1
                print("  · lyrics: already present", flush=True)
            else:
                lyrics = _fetch_lyrics(base)
                if lyrics:
                    storage.upload_bytes(lkey, lyrics.encode("utf-8"), "text/plain; charset=utf-8")
                    counts["lyrics"] += 1
                    print(f"  ✓ lyrics: {len(lyrics)} chars", flush=True)
                else:
                    print("  ! lyrics not found", flush=True)

            # 3. Instrumental render (only if the tools are installed).
            if not soundfont:
                continue
            ikey = f"hymns/{slug}.mp3"
            if storage.exists(ikey):
                counts["instrumental"] += 1
                print("  · instrumental: already present", flush=True)
                continue
            if midi not in names:
                print(f"  ! instrumental: MIDI {midi!r} not in bundle", flush=True)
                continue
            try:
                mp3 = _render_mp3(bundle.read(midi), soundfont, tmp)
            except subprocess.CalledProcessError as exc:
                print(f"  ! instrumental render failed: {(exc.stderr or b'').decode(errors='replace')[:120]}", flush=True)
                continue
            storage.upload_bytes(ikey, mp3, "audio/mpeg")
            counts["instrumental"] += 1
            print(f"  ✓ instrumental: {len(mp3)//1024} KB", flush=True)

    print(f"\nDone. sung={counts['sung']}  instrumental={counts['instrumental']}  "
          f"lyrics={counts['lyrics']}  (of {len(HYMNS)} hymns)", flush=True)
    if counts["sung"] == 0 and counts["instrumental"] == 0:
        sys.exit("No audio seeded — both hymn sources will be unavailable.")


if __name__ == "__main__":
    main()
