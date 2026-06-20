"""MusicGen strategy — generate worship music locally using Meta's MusicGen.

Uses facebook/musicgen-small by default (~300 MB, CPU-capable). The model is
loaded fresh per generation and released afterward so memory is freed between
runs. A Redis lock ensures only one generation runs at a time — concurrent
MusicGen on a 8 GB box would otherwise exhaust RAM.

Generation time on 2 vCPU: ~5–8 min for a 30-second track.
Generation time on 4 vCPU: ~3–5 min.

Env vars (all optional):
  MUSICGEN_MODEL      HuggingFace model id (default: facebook/musicgen-small)
  MUSICGEN_MAX_TOKENS Tokens to generate; 50 tokens ≈ 1 second (default: 750 → ~15 s)
  MUSICGEN_DEVICE     auto | cpu | cuda | cuda:N  (default: auto — picks CUDA if available)
  MUSICGEN_LOCK_TTL   Redis lock TTL in seconds (default: 1800 → 30 min)
  REDIS_URL           Redis broker URL (default: redis://localhost:6379/0)
"""

from __future__ import annotations

import io
import os
import shutil
import subprocess
import tempfile
import time

import numpy as np
import storage

from . import MusicResult, MusicStrategy

_MODEL = os.getenv("MUSICGEN_MODEL", "facebook/musicgen-small")
_MAX_TOKENS = int(os.getenv("MUSICGEN_MAX_TOKENS", "750"))
# MUSICGEN_DEVICE: "auto" (detect GPU), "cpu", "cuda", or "cuda:N"
_DEVICE = os.getenv("MUSICGEN_DEVICE", "auto")
# Redis lock TTL — must be longer than the worst-case generation time.
# GPU reduces generation time significantly (minutes → seconds for small model).
_LOCK_TTL = int(os.getenv("MUSICGEN_LOCK_TTL", "1800"))
_REDIS_URL = os.getenv("REDIS_URL", "redis://localhost:6379/0")
_LOCK_KEY = "musicgen:lock"


def _resolve_device() -> str:
    """Return the best available device, respecting MUSICGEN_DEVICE env var."""
    if _DEVICE != "auto":
        return _DEVICE
    try:
        import torch
        if torch.cuda.is_available():
            return "cuda"
    except ImportError:
        pass
    return "cpu"


def _redis_lock():
    """Return a redis-py client and a simple SETNX-based lock handle."""
    import redis as _redis
    client = _redis.from_url(_REDIS_URL, decode_responses=True)
    return client


def _acquire_lock(client, timeout: int = 900) -> bool:
    """Try to acquire the MusicGen lock, waiting up to `timeout` seconds."""
    deadline = time.time() + timeout
    while time.time() < deadline:
        ok = client.set(_LOCK_KEY, "1", nx=True, ex=_LOCK_TTL)
        if ok:
            return True
        remaining = deadline - time.time()
        wait = min(15, max(1, remaining / 4))
        print(f"[musicgen] waiting for lock (another generation in progress)…", flush=True)
        time.sleep(wait)
    return False


def _release_lock(client) -> None:
    client.delete(_LOCK_KEY)


_MOOD_PROMPTS: dict[str, str] = {
    "grateful":  "uplifting worship song, piano, warm choir, major key, bright",
    "anxious":   "gentle calming worship, soft piano, peaceful strings, reassuring",
    "grieving":  "tender comforting hymn, strings, soft piano, consoling, minor key",
    "joyful":    "joyful celebratory worship, bright piano, choir, upbeat, major key",
    "seeking":   "contemplative worship, acoustic guitar, searching, hopeful, quiet",
    "hopeful":   "hopeful inspirational worship, acoustic guitar, building, triumphant",
}


class MusicGenStrategy(MusicStrategy):
    def fetch(self, *, mood: str, prompt: str, query: str) -> MusicResult:
        text = self._build_prompt(mood, prompt)
        print(f"[musicgen] prompt: {text[:100]!r}", flush=True)

        client = _redis_lock()
        if not _acquire_lock(client, timeout=900):
            raise RuntimeError("[musicgen] could not acquire generation lock within 15 min")

        try:
            result = self._generate(text, prompt)
        except Exception:
            _release_lock(client)
            raise
        else:
            _release_lock(client)

        return result

    def _generate(self, text: str, full_prompt: str) -> MusicResult:
        marker = "\n\nLyrics:\n"
        lyrics = full_prompt.split(marker, 1)[1].strip() if marker in full_prompt else None

        audio_bytes, ext, content_type = generate_instrumental(text, max_tokens=_MAX_TOKENS)

        key = f"worship/musicgen_{int(time.time())}.{ext}"
        storage.upload_bytes(key, audio_bytes, content_type)

        return MusicResult(
            asset_type="audio",
            storage_key=key,
            provider_ref=f"musicgen:{int(time.time())}",
            title=f"AI Worship ({text.split(',')[0].strip().title()})",
            lyrics=lyrics,
        )

    def _build_prompt(self, mood: str, prompt: str) -> str:
        marker = "\n\nLyrics:\n"
        if marker in (prompt or ""):
            style = prompt.split(marker, 1)[0].strip()
            if style:
                return style

        return _MOOD_PROMPTS.get(
            mood.lower(),
            f"Christian worship music, {mood}, piano, choir, peaceful",
        )


def generate_instrumental(text: str, *, max_tokens: int = _MAX_TOKENS) -> tuple[bytes, str, str]:
    """Run MusicGen on a text prompt and return (audio_bytes, ext, content_type).

    Shared by the worship MusicGenStrategy and the Bible background-music task so
    there is a single generation/encoding/memory-release code path. Callers are
    responsible for any concurrency lock and for storing the returned bytes.
    """
    from transformers import pipeline as hf_pipeline

    device = _resolve_device()

    # float16 halves VRAM usage and speeds up inference on CUDA; CPU needs float32.
    pipeline_kwargs: dict = {"model": _MODEL, "device": device}
    try:
        import torch
        pipeline_kwargs["torch_dtype"] = torch.float16 if "cuda" in device else torch.float32
    except ImportError:
        pass

    print(f"[musicgen] loading {_MODEL!r} on {device}…", flush=True)
    t0 = time.time()
    pipeline = hf_pipeline("text-to-audio", **pipeline_kwargs)
    print(f"[musicgen] model ready in {time.time() - t0:.1f}s", flush=True)

    try:
        t1 = time.time()
        result = pipeline(
            text,
            forward_params={"do_sample": True, "max_new_tokens": max_tokens},
        )
        print(f"[musicgen] generated in {time.time() - t1:.1f}s", flush=True)

        item = result[0] if isinstance(result, list) else result
        audio = np.asarray(item["audio"])
        sr = int(item["sampling_rate"])
        return _encode_audio(audio, sr)
    finally:
        # Explicitly release the model and PyTorch tensors so the memory is
        # returned to the OS before the next task runs.
        del pipeline
        try:
            import torch
            torch.cuda.empty_cache()
        except Exception:
            pass
        import gc
        gc.collect()


def _encode_audio(audio: np.ndarray, sample_rate: int) -> tuple[bytes, str, str]:
    """Encode generated audio, preferring MP3 and falling back to WAV."""
    import scipy.io.wavfile as wavfile

    audio = audio.squeeze()
    if audio.ndim == 2:
        audio = audio.mean(axis=0)

    audio_int16 = (np.clip(audio, -1.0, 1.0) * 32767).astype(np.int16)

    if shutil.which("ffmpeg") is None:
        buf = io.BytesIO()
        wavfile.write(buf, sample_rate, audio_int16)
        return buf.getvalue(), "wav", "audio/wav"

    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as wf:
        wav_path = wf.name
        wavfile.write(wav_path, sample_rate, audio_int16)

    mp3_path = wav_path.replace(".wav", ".mp3")
    try:
        try:
            subprocess.run(
                [
                    "ffmpeg", "-y",
                    "-i", wav_path,
                    "-codec:a", "libmp3lame",
                    "-q:a", "4",
                    mp3_path,
                ],
                check=True,
                capture_output=True,
            )
            with open(mp3_path, "rb") as f:
                return f.read(), "mp3", "audio/mpeg"
        except subprocess.CalledProcessError:
            with open(wav_path, "rb") as f:
                return f.read(), "wav", "audio/wav"
    finally:
        for path in (wav_path, mp3_path):
            try:
                os.unlink(path)
            except OSError:
                pass
