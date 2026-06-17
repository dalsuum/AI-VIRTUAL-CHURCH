"""RunPod serverless handler for fast lip-sync avatars (Wav2Lip).

Drop-in replacement for the SadTalker worker: same input/output contract, so the
avatar_proxy, Celery worker, and frontend need no changes. Wav2Lip is ~5-10x faster
than SadTalker (near real-time) because it only animates the mouth region instead of
reconstructing the whole head in 3D — which is what makes full-length sermons feasible.

Input  (RunPod wraps it as {"input": {...}}):
    {"image_b64": "<base64 JPEG/PNG portrait>", "audio_b64": "<base64 WAV/MP3>"}

Output:
    {"video_b64": "<base64 MP4>"}        on success
    {"error": "..."}                     on failure
"""
import base64
import os
import subprocess
import tempfile

import runpod

WAV2LIP_DIR = os.getenv("WAV2LIP_DIR", "/app/Wav2Lip")
CHECKPOINT = os.path.join(WAV2LIP_DIR, "checkpoints", "wav2lip_gan.pth")


def _write_temp(data_b64: str, suffix: str) -> str:
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

    image_path = audio_path = out_path = None
    try:
        # A .jpg face makes Wav2Lip treat it as a static portrait (mouth-only animation).
        image_path = _write_temp(image_b64, ".jpg")
        audio_path = _write_temp(audio_b64, ".wav")
        fd, out_path = tempfile.mkstemp(suffix=".mp4")
        os.close(fd)

        cmd = [
            "python3", "inference.py",
            "--checkpoint_path", CHECKPOINT,
            "--face", image_path,
            "--audio", audio_path,
            "--outfile", out_path,
            "--pads", "0", "15", "0", "0",   # a little chin padding so the mouth isn't clipped
            "--nosmooth",                      # static portrait: smoothing across frames hurts
        ]
        proc = subprocess.run(
            cmd, cwd=WAV2LIP_DIR, capture_output=True, text=True, timeout=600
        )
        if proc.returncode != 0:
            return {"error": f"Wav2Lip failed: {proc.stderr[-2000:]}"}
        if not os.path.exists(out_path) or os.path.getsize(out_path) == 0:
            return {"error": f"no output produced. log: {proc.stdout[-1000:]}"}

        with open(out_path, "rb") as f:
            return {"video_b64": base64.b64encode(f.read()).decode("ascii")}
    except subprocess.TimeoutExpired:
        return {"error": "Wav2Lip render timed out"}
    finally:
        for p in (image_path, audio_path, out_path):
            if p and os.path.exists(p):
                os.remove(p)


runpod.serverless.start({"handler": handler})
