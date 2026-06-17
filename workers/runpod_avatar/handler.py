"""RunPod serverless handler for audio-driven talking-head avatars (SadTalker).

Takes a still portrait + a narration audio clip and returns a lip-synced MP4 of
the portrait "speaking" that audio. SadTalker is invoked via its own inference
script in a subprocess so we stay decoupled from its internal Python API (which
changes between checkpoints). The repo + checkpoints are baked into the image, so
cold start only pays for loading weights onto the GPU, not downloading them.

Input  (RunPod wraps it as {"input": {...}}):
    {"image_b64": "<base64 JPEG/PNG>", "audio_b64": "<base64 WAV/MP3>",
     "still": true, "preprocess": "full"}
    - image_b64: required, base64 of the presenter portrait
    - audio_b64: required, base64 of the narration audio to lip-sync to
    - still:      optional (default true) — less head motion, more stable
    - preprocess: optional (default "full") — SadTalker crop mode

Output:
    {"video_b64": "<base64 MP4>"}        on success
    {"error": "..."}                     on failure
"""
import base64
import glob
import os
import subprocess
import tempfile

import runpod

SADTALKER_DIR = os.getenv("SADTALKER_DIR", "/app/SadTalker")
CHECKPOINT_DIR = os.path.join(SADTALKER_DIR, "checkpoints")
CONFIG_DIR = os.path.join(SADTALKER_DIR, "src", "config")


def _write_temp(data_b64: str, suffix: str) -> str:
    """Decode a base64 payload to a temp file and return its path."""
    fd, path = tempfile.mkstemp(suffix=suffix)
    with os.fdopen(fd, "wb") as f:
        f.write(base64.b64decode(data_b64))
    return path


def handler(job):
    inp = job.get("input") or {}
    image_b64 = inp.get("image_b64")
    audio_b64 = inp.get("audio_b64")
    if not image_b64 or not audio_b64:
        return {"error": "both 'image_b64' and 'audio_b64' are required"}

    still = inp.get("still", True)
    preprocess = inp.get("preprocess") or "full"

    image_path = audio_path = None
    out_dir = tempfile.mkdtemp(prefix="sadtalker_out_")
    try:
        image_path = _write_temp(image_b64, ".jpg")
        audio_path = _write_temp(audio_b64, ".wav")

        cmd = [
            "python3", "inference.py",
            "--driven_audio", audio_path,
            "--source_image", image_path,
            "--result_dir", out_dir,
            "--checkpoint_dir", CHECKPOINT_DIR,
            "--preprocess", preprocess,
        ]
        # SadTalker runs on CUDA by default; --cpu is the only device flag it accepts
        # (there is no --bfloat16). Only force CPU when no GPU is present.
        if not _cuda():
            cmd.append("--cpu")
        if still:
            cmd.append("--still")

        proc = subprocess.run(
            cmd, cwd=SADTALKER_DIR, capture_output=True, text=True, timeout=270
        )
        if proc.returncode != 0:
            return {"error": f"SadTalker failed: {proc.stderr[-2000:]}"}

        # SadTalker writes the final mp4 into result_dir (timestamped name).
        videos = sorted(glob.glob(os.path.join(out_dir, "**", "*.mp4"), recursive=True))
        if not videos:
            return {"error": f"no output video produced. log: {proc.stdout[-1000:]}"}

        with open(videos[-1], "rb") as f:
            return {"video_b64": base64.b64encode(f.read()).decode("ascii")}
    except subprocess.TimeoutExpired:
        return {"error": "SadTalker render timed out"}
    finally:
        for p in (image_path, audio_path):
            if p and os.path.exists(p):
                os.remove(p)


def _cuda() -> bool:
    try:
        import torch
        return torch.cuda.is_available()
    except Exception:  # noqa: BLE001
        return False


runpod.serverless.start({"handler": handler})
