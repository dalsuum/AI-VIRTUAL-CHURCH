#!/usr/bin/env python3
"""
Vocal-onset detection for the Father's Day (Special Day) MV — REMOVABLE.

Given a song, returns the time (seconds) the singing starts, so the MV renderer
can hold the lyrics until vocals come in instead of showing them over the
instrumental intro.

Method: separate the vocal stem with Demucs and find the first sustained vocal
energy. Only the first CLIP seconds are analysed — vocals essentially always
enter within that window — which keeps it fast and within memory.

Uses the Demucs Python API in-memory (reads the clip via ffmpeg ourselves and
feeds a tensor straight to the model) so it needs neither torchaudio's file I/O
nor torchcodec, which the server's torch build lacks.

Runs in the isolated venv at workers/.venv-demucs (kept apart from the worker
venv so its torch can't disturb the narration/avatar stack).

Usage:
    .venv-demucs/bin/python vocal_start.py /path/song.mp3
Prints one JSON line: {"vocal_start": 17.4}  (0.0 if none detected)
"""

import json
import subprocess
import sys

import numpy as np

CLIP_SECONDS = 90          # only analyse the intro window
WIN_MS = 50                # RMS window size
MIN_VOICED_SEC = 0.35      # vocals must persist this long to count as the start
SR = 44100                 # htdemucs sample rate


def read_clip(song: str) -> np.ndarray:
    """Decode the first CLIP_SECONDS to float32 stereo [2, N] via ffmpeg."""
    proc = subprocess.run(
        ["ffmpeg", "-hide_banner", "-loglevel", "error",
         "-t", str(CLIP_SECONDS), "-i", song,
         "-ac", "2", "-ar", str(SR), "-f", "f32le", "-"],
        check=True, stdout=subprocess.PIPE,
    )
    audio = np.frombuffer(proc.stdout, dtype=np.float32)
    return audio.reshape(-1, 2).T.copy()        # [channels=2, samples]


def vocal_stem(clip: np.ndarray):
    """Run Demucs htdemucs and return the vocals stem as a 1-D numpy array."""
    import torch
    from demucs.apply import apply_model
    from demucs.pretrained import get_model

    torch.set_num_threads(2)
    model = get_model("htdemucs")
    model.cpu().eval()

    wav = torch.from_numpy(clip)                # [2, N]
    ref = wav.mean(0)
    wav = (wav - ref.mean()) / (ref.std() + 1e-8)

    with torch.no_grad():
        out = apply_model(model, wav[None], device="cpu", segment=7,
                          overlap=0.1, progress=False)[0]
    out = out * ref.std() + ref.mean()

    vocals = out[model.sources.index("vocals")]  # [2, N]
    return vocals.mean(0).numpy()                 # mono


def detect_onset(vocals: np.ndarray) -> float:
    """First time (sec) the vocal stem has sustained energy above the floor."""
    if vocals.size == 0:
        return 0.0
    win = max(1, int(SR * WIN_MS / 1000))
    nwin = vocals.size // win
    if nwin == 0:
        return 0.0
    rms = np.sqrt((vocals[: nwin * win].reshape(nwin, win) ** 2).mean(axis=1))

    floor = np.percentile(rms, 10)
    peak = np.percentile(rms, 95)
    if peak - floor < 1e-4:
        return 0.0                               # essentially silent -> no vocals
    thresh = floor + 0.20 * (peak - floor)

    need = max(1, int(MIN_VOICED_SEC * 1000 / WIN_MS))
    run = 0
    for i, v in enumerate(rms):
        if v >= thresh:
            run += 1
            if run >= need:
                return round(max(0.0, (i - need + 1) * WIN_MS / 1000.0), 2)
        else:
            run = 0
    return 0.0


def main() -> int:
    if len(sys.argv) < 2:
        print(json.dumps({"vocal_start": 0.0, "error": "no song path"}))
        return 1
    try:
        clip = read_clip(sys.argv[1])
        vocals = vocal_stem(clip)
        print(json.dumps({"vocal_start": detect_onset(vocals)}))
        return 0
    except Exception as e:  # noqa: BLE001 - report and let caller default to 0
        print(json.dumps({"vocal_start": 0.0, "error": str(e)}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
