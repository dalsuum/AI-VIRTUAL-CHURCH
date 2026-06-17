"""LRC "Tapper": capture line-by-line lyric timings for a sung hymn.

Plays a hymn's local sung MP3 and lets you tap the spacebar at each line
boundary. The captured timings are merged back into the hymn object in the
matching JSON library, keyed by slug, next to the lyrics that already live
there — no DB schema, no API. See docs/lrc-static-sync-spike.md.

    python workers/tools/tap_lyrics.py td-a-dahte-hong-pai-un
    python workers/tools/tap_lyrics.py my-some-slug --audio /path/to/file.mp3

Controls while playing:
    SPACE  mark the start of the *next* lyric line
    u      undo the last mark
    r      restart the take (clears marks, replays from the top)
    q      finish: write the marks gathered so far and quit
    Ctrl-C abort without writing

v1 scope: timings bind to the **sung** render (`{slug}.sung.mp3`). Language is
inferred from the slug prefix (`td-`/`my-`); English (hymns.py, no JSON) is
out of scope. Requires ffplay + ffprobe (from ffmpeg). Re-running overwrites
the existing `timings` for that slug (with a confirm prompt).
"""

from __future__ import annotations

import argparse
import json
import os
import select
import shutil
import subprocess
import sys
import termios
import time
import tty

# workers/tools/ -> workers/
WORKERS_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA_DIR = os.path.join(WORKERS_DIR, "data")
# Sung renders live in the Laravel public storage, one dir up from workers/.
HYMNS_AUDIO_DIR = os.path.join(
    os.path.dirname(WORKERS_DIR),
    "backend", "storage", "app", "public", "hymns",
)

LIBRARIES = {
    "td": os.path.join(DATA_DIR, "hymns_td.json"),
    "my": os.path.join(DATA_DIR, "hymns_my.json"),
}


def lang_for_slug(slug: str) -> str:
    """Infer the JSON library from the slug prefix; abort on anything else."""
    prefix = slug.split("-", 1)[0]
    if prefix not in LIBRARIES:
        sys.exit(
            f"Slug '{slug}' has no supported language prefix. "
            f"Expected one of: {', '.join(p + '-' for p in LIBRARIES)} "
            "(English hymns live in hymns.py and are out of scope for v1)."
        )
    return prefix


def load_library(path: str) -> dict:
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)


def find_hymn(library: dict, slug: str) -> dict:
    for hymn in library.get("hymns", []):
        if hymn.get("slug") == slug:
            return hymn
    sys.exit(f"Slug '{slug}' not found in the library.")


def lyric_lines(hymn: dict) -> list[str]:
    """Non-empty lyric lines, in order — one timing mark is captured per line."""
    raw = (hymn.get("lyrics") or "").splitlines()
    lines = [ln.strip() for ln in raw if ln.strip()]
    if not lines:
        sys.exit(f"Hymn '{hymn.get('slug')}' has no lyrics to time.")
    return lines


def resolve_audio(slug: str, override: str | None) -> str:
    if override:
        if not os.path.isfile(override):
            sys.exit(f"Audio file not found: {override}")
        return override
    path = os.path.join(HYMNS_AUDIO_DIR, f"{slug}.sung.mp3")
    if not os.path.isfile(path):
        sys.exit(
            f"No sung render at {path}\n"
            "Pass --audio to point at the MP3 explicitly."
        )
    return path


def audio_duration(path: str) -> float:
    """Best-effort duration via ffprobe; 0.0 if it can't be read."""
    try:
        out = subprocess.check_output(
            [
                "ffprobe", "-v", "error", "-show_entries", "format=duration",
                "-of", "default=noprint_wrappers=1:nokey=1", path,
            ],
            text=True,
        )
        return float(out.strip())
    except (subprocess.CalledProcessError, ValueError, OSError):
        return 0.0


def require_tools() -> None:
    missing = [t for t in ("ffplay", "ffprobe") if shutil.which(t) is None]
    if missing:
        sys.exit(
            f"Missing required tool(s): {', '.join(missing)}. "
            "Install ffmpeg (provides ffplay + ffprobe)."
        )


def tap_session(audio_path: str, lines: list[str]) -> list[dict] | None:
    """Play the audio and gather one timestamp per line via spacebar taps.

    Returns the timings list, or None if the user aborted. Raw terminal mode
    is used so single keystrokes register without Enter; it is always restored.
    """
    if not sys.stdin.isatty():
        sys.exit("Tapper needs an interactive terminal (a TTY) to read taps.")

    total = len(lines)
    fd = sys.stdin.fileno()
    saved = termios.tcgetattr(fd)

    while True:  # restart loop ('r')
        marks: list[float] = []
        player = subprocess.Popen(
            ["ffplay", "-nodisp", "-autoexit", "-loglevel", "quiet", audio_path],
            stdin=subprocess.DEVNULL,
        )
        start = time.monotonic()
        restart = False

        print("\n▶  Playing. Tap SPACE at each line start "
              "(u=undo  r=restart  q=finish).\n")
        _print_next(marks, lines)

        try:
            tty.setcbreak(fd)
            while True:
                if player.poll() is not None and len(marks) >= total:
                    break  # audio ended and every line is marked
                # Wake at least 4x/sec so audio-end is noticed even without keys.
                ready, _, _ = select.select([sys.stdin], [], [], 0.25)
                if not ready:
                    continue
                ch = sys.stdin.read(1)
                now = time.monotonic() - start

                if ch == " ":
                    if len(marks) < total:
                        marks.append(round(now, 2))
                        _print_next(marks, lines)
                        if len(marks) == total:
                            print("\n✓ All lines marked. Press q to finish.")
                elif ch in ("u", "U"):
                    if marks:
                        removed = marks.pop()
                        print(f"  ↩ undo (removed {removed:.2f}s)")
                        _print_next(marks, lines)
                elif ch in ("r", "R"):
                    restart = True
                    break
                elif ch in ("q", "Q"):
                    break
        finally:
            termios.tcsetattr(fd, termios.TCSADRAIN, saved)
            if player.poll() is None:
                player.terminate()
                player.wait()

        if restart:
            print("\n⟲ restarting take…")
            continue

        if not marks:
            print("\nNo taps recorded.")
            return None
        return [
            {"time": t, "line_index": i} for i, t in enumerate(marks)
        ]


def _print_next(marks: list[float], lines: list[str]) -> None:
    idx = len(marks)
    if idx < len(lines):
        elapsed = f"{marks[-1]:.2f}s " if marks else ""
        print(f"  {elapsed}[{idx + 1}/{len(lines)}] next: {lines[idx]}")


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Capture LRC line timings for a sung hymn by tapping along."
    )
    parser.add_argument("slug", help="Hymn slug, e.g. td-a-dahte-hong-pai-un")
    parser.add_argument(
        "--audio",
        help="Path to the sung MP3 (defaults to "
             "backend/storage/app/public/hymns/<slug>.sung.mp3)",
    )
    args = parser.parse_args()

    require_tools()
    lang = lang_for_slug(args.slug)
    lib_path = LIBRARIES[lang]
    library = load_library(lib_path)
    hymn = find_hymn(library, args.slug)
    lines = lyric_lines(hymn)
    audio_path = resolve_audio(args.slug, args.audio)

    dur = audio_duration(audio_path)
    print(f"Hymn:   {hymn.get('title', args.slug)}  ({args.slug})")
    print(f"Lines:  {len(lines)}")
    print(f"Audio:  {audio_path}" + (f"  ({dur:.1f}s)" if dur else ""))

    if hymn.get("timings"):
        ans = input(
            f"\n{args.slug} already has {len(hymn['timings'])} timings. "
            "Overwrite? [y/N] "
        ).strip().lower()
        if ans != "y":
            sys.exit("Aborted — existing timings kept.")

    timings = tap_session(audio_path, lines)
    if timings is None:
        sys.exit("Nothing captured — library unchanged.")

    hymn["timings"] = timings
    # Match the library's existing on-disk style (1-space indent, literal
    # unicode, no trailing newline) so the diff is just the new timings.
    with open(lib_path, "w", encoding="utf-8") as fh:
        json.dump(library, fh, ensure_ascii=False, indent=1)

    print(f"\n✓ Wrote {len(timings)} timings for {args.slug} → {lib_path}")


if __name__ == "__main__":
    main()
