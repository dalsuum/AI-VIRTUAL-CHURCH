"""MusicGen strategy — generate worship music locally using Meta's MusicGen.

Uses facebook/musicgen-small by default (~300 MB, CPU-capable). The model is
loaded once per worker process and stays resident in RAM between services.

Generation time on 2 vCPU: ~5–8 min for a 30-second track.
Generation time on 4 vCPU: ~3–5 min.

Env vars (all optional):
  MUSICGEN_MODEL      HuggingFace model id (default: facebook/musicgen-small)
  MUSICGEN_MAX_TOKENS Tokens to generate; 50 tokens ≈ 1 second (default: 1500 → ~30 s)
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
_MAX_TOKENS = int(os.getenv("MUSICGEN_MAX_TOKENS", "1500"))

# Module-level cache: the model loads once per Celery worker process (~300 MB RAM).
_pipeline = None


def _get_pipeline():
    global _pipeline
    if _pipeline is None:
        from transformers import pipeline as hf_pipeline

        print(f"[musicgen] loading {_MODEL!r} (first call — may take a minute)…", flush=True)
        t0 = time.time()
        _pipeline = hf_pipeline(
            "text-to-audio",
            model=_MODEL,
            device="cpu",
        )
        print(f"[musicgen] model ready in {time.time() - t0:.1f}s", flush=True)
    return _pipeline


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

        t0 = time.time()
        result = _get_pipeline()(
            text,
            forward_params={"do_sample": True, "max_new_tokens": _MAX_TOKENS},
        )
        print(f"[musicgen] generated in {time.time() - t0:.1f}s", flush=True)

        # HF pipelines often return a one-item list for single-input calls.
        item = result[0] if isinstance(result, list) else result
        audio = np.asarray(item["audio"])
        sr = int(item["sampling_rate"])
        audio_bytes, ext, content_type = _encode_audio(audio, sr)

        key = f"worship/musicgen_{int(time.time())}.{ext}"
        storage.upload_bytes(key, audio_bytes, content_type)

        return MusicResult(
            asset_type="audio",
            storage_key=key,
            provider_ref=f"musicgen:{int(time.time())}",
            title=f"AI Worship ({mood.title()})",
            lyrics=None,
        )

    def _build_prompt(self, mood: str, prompt: str) -> str:
        # The orchestrator prompt has a Suno-style section before "\n\nLyrics:\n".
        # Use that style description as the MusicGen text prompt.
        marker = "\n\nLyrics:\n"
        if marker in (prompt or ""):
            style = prompt.split(marker, 1)[0].strip()
            if style:
                return style

        # Fall back to a mood-mapped description.
        return _MOOD_PROMPTS.get(
            mood.lower(),
            f"Christian worship music, {mood}, piano, choir, peaceful",
        )


def _encode_audio(audio: np.ndarray, sample_rate: int) -> tuple[bytes, str, str]:
    """Encode generated audio, preferring MP3 and falling back to WAV."""
    import scipy.io.wavfile as wavfile

    # Collapse to mono 1-D array, handling (batch, channels, samples) and variants.
    audio = audio.squeeze()
    if audio.ndim == 2:
        audio = audio.mean(axis=0)

    # Float32 [-1, 1] → int16
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
                    "-q:a", "4",          # VBR quality 4 ≈ ~165 kbps, good for music
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
