"""OPTIONAL: seed Tedim instrumental renders from the Tedim Hymn 7th Edition MIDI library.

Downloads ~448 MIDI tune files and renders each to an instrumental MP3
(fluidsynth + ffmpeg — same toolchain as seed_hymns.py), stored under:

    hymns_td/inst/<NORMALIZED-TITLE>.mp3

TedimHymnStrategy uses these as its LAST fallback: a selected hymn with no
YouTube embed and no Suno credit available can still play instrumentally with
the verses on screen. Matching is by normalized title (letters only, uppercased).

    sudo apt install fluidsynth fluid-soundfont-gm ffmpeg
    python workers/tools/seed_tedim_midi.py [--limit N]

Skips gracefully (with a message) if fluidsynth/ffmpeg are missing; safe to
re-run — already-rendered titles are skipped.
"""

from __future__ import annotations

import argparse
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time

import requests

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
import storage  # noqa: E402

BASE = "https://tedimhymn.com/"
HEADERS = {"User-Agent": "aivirtual.church seeder (contact: site admin)"}
KEY = "hymns_td/inst/{norm}.mp3"

_SOUNDFONTS = [
    "/usr/share/sounds/sf2/FluidR3_GM.sf2",
    "/usr/share/sounds/sf2/default-GM.sf2",
    "/usr/share/soundfonts/FluidR3_GM.sf2",
    "/usr/share/soundfonts/default.sf2",
]


def norm_title(name: str) -> str:
    """Letters only, uppercased — shared with TedimHymnStrategy's lookup."""
    return re.sub(r"[^A-Z]", "", name.upper())


def _soundfont() -> str | None:
    return next((p for p in _SOUNDFONTS if os.path.exists(p)), None)


def _midi_urls() -> list[tuple[str, str]]:
    """(url, normalized-title) for every MIDI linked from the homepage."""
    html = requests.get(BASE, headers=HEADERS, timeout=30).text
    out, seen = [], set()
    for rel in re.findall(r"href=[\"']([^\"']*upload/midi/[^\"']+\.midi?)[\"']", html, re.I):
        fname = rel.rsplit("/", 1)[-1]
        title = re.sub(r"^\d+[A-Z]?\.\s*", "", fname.rsplit(".", 1)[0])  # drop "001. "
        norm = norm_title(title)
        if norm and norm not in seen:
            seen.add(norm)
            out.append((BASE.rstrip("/") + "/" + rel.lstrip("/"), norm))
    return out


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int, default=0)
    args = ap.parse_args()

    sf = _soundfont()
    if not (sf and shutil.which("fluidsynth") and shutil.which("ffmpeg")):
        print("fluidsynth + soundfont + ffmpeg required — skipping instrumental seed.\n"
              "    sudo apt install fluidsynth fluid-soundfont-gm ffmpeg")
        return

    urls = _midi_urls()
    if args.limit:
        urls = urls[: args.limit]
    print(f"{len(urls)} MIDI tunes from {BASE}")

    done = skipped = failed = 0
    for url, norm in urls:
        key = KEY.format(norm=norm[:80])
        if storage.exists(key):
            skipped += 1
            continue
        try:
            midi = requests.get(url, headers=HEADERS, timeout=30).content
            with tempfile.TemporaryDirectory() as tmp:
                mid, wav, mp3 = (os.path.join(tmp, f"t.{e}") for e in ("mid", "wav", "mp3"))
                open(mid, "wb").write(midi)
                subprocess.run(["fluidsynth", "-ni", sf, mid, "-F", wav, "-r", "44100"],
                               check=True, capture_output=True)
                subprocess.run(["ffmpeg", "-y", "-i", wav, "-b:a", "128k", mp3],
                               check=True, capture_output=True)
                storage.upload_bytes(key, open(mp3, "rb").read(), "audio/mpeg")
            done += 1
            time.sleep(0.4)  # be a polite guest
        except Exception as exc:  # noqa: BLE001 — render what we can
            failed += 1
            print(f"  FAILED {url.rsplit('/',1)[-1]}: {exc}")
    print(f"rendered {done}, skipped {skipped} existing, {failed} failed")


if __name__ == "__main__":
    main()
