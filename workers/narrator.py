"""Narration (text-to-speech) for the spoken segments.

Each spoken segment's words — opening prayer, scripture, message, benediction — are
sent to a text-to-speech endpoint, the returned audio is stored, and a playable URL
is handed back so the segment can be *heard* as well as read.

Providers (set via Admin Console → Settings → Narration voice):

  'edge_tts' — Microsoft Edge TTS: free, no API key, high-quality neural voices.
    For English: EDGE_TTS_VOICE_FEMALE / EDGE_TTS_VOICE_MALE (default en-US-Aria/GuyNeural)
    For Myanmar: EDGE_TTS_VOICE_MY_FEMALE / EDGE_TTS_VOICE_MY_MALE (default my-MM-Nilar/ThihaNeural)
    For Tedim:  EDGE_TTS_VOICE_TD (default en-US-AriaNeural; no native Zolai Edge voice)
    EDGE_TTS_RATE — speaking rate adjustment, e.g. '-5%' to slow down (default '')

  'mms_tts' — Local Facebook MMS-TTS (free, offline). Best native quality for
    Burmese and Tedim. Requires the aivc-mms-tts container (port 8003).
    Myanmar: facebook/mms-tts-mya  |  Tedim: facebook/mms-tts-ctd
    MMS_SPEECH_URL / MMS_TTS_URL — service base URL (default http://127.0.0.1:8003)

  'openai' — OpenAI's own (or any compatible gateway) text-to-speech:
    TTS_API_KEY    — required to enable this provider at all
    TTS_BASE_URL   — API host (default https://api.openai.com/v1)
    TTS_MODEL      — speech model (default gpt-4o-mini-tts)
    TTS_VOICE      — the voice to read with (default 'onyx', a warm narrator)
    TTS_FORMAT     — audio container (default mp3)

  'kokoro' — the open hexgrad/kokoro-82m model served through OpenRouter (or any
  compatible gateway). Falls back to the OPENROUTER_* credentials the LLM already
  uses, so it works out of the box once those are set:
    KOKORO_API_KEY  — provider key (default: OPENROUTER_API_KEY)
    KOKORO_BASE_URL — API host (default: OPENROUTER_BASE_URL or OpenRouter's)
    KOKORO_MODEL    — speech model (default hexgrad/kokoro-82m)
    KOKORO_VOICE    — the voice to read with (default 'af_heart')
    KOKORO_FORMAT   - audio container (default: TTS_FORMAT or mp3)

  'voicebox' — Local Voicebox container on port 17493. The current Docker image
    exposes POST /generate and GET /audio/{generation_id}; model choice is Qwen
    0.6B by default, or 1.7B via VOICEBOX_ENGINE=qwen_1_7b / VOICEBOX_MODEL_SIZE=1.7B.
"""

from __future__ import annotations

import asyncio
import os
import re

import requests

import storage

# TTS endpoints cap a single request near 4096 characters; longer scripts (the
# message especially) are split on sentence boundaries and the audio is stitched
# back together rather than truncated.
_MAX_CHARS = int(os.getenv("TTS_MAX_CHARS", "3500"))


def _providers(gender: str = "female") -> dict[str, dict]:
    """The API-based voice providers keyed by narration_mode.

    Gender-specific voices are resolved via TTS_VOICE_MALE/FEMALE and
    KOKORO_VOICE_MALE/FEMALE env vars, falling back to the bare TTS_VOICE/KOKORO_VOICE."""
    suffix = gender.upper()
    return {
        "openai": {
            "api_key": os.getenv("TTS_API_KEY"),
            "base_url": os.getenv("TTS_BASE_URL", "https://api.openai.com/v1").rstrip("/"),
            "model": os.getenv("TTS_MODEL", "gpt-4o-mini-tts"),
            "voice": os.getenv(f"TTS_VOICE_{suffix}") or os.getenv("TTS_VOICE", "onyx"),
            "fmt": os.getenv("TTS_FORMAT", "mp3"),
        },
        "kokoro": {
            "api_key": os.getenv("KOKORO_API_KEY") or os.getenv("OPENROUTER_API_KEY"),
            "base_url": (os.getenv("KOKORO_BASE_URL")
                         or os.getenv("OPENROUTER_BASE_URL", "https://openrouter.ai/api/v1")).rstrip("/"),
            "model": os.getenv("KOKORO_MODEL", "hexgrad/kokoro-82m"),
            "voice": os.getenv(f"KOKORO_VOICE_{suffix}") or os.getenv("KOKORO_VOICE", "af_heart"),
            "fmt": os.getenv("KOKORO_FORMAT") or os.getenv("TTS_FORMAT", "mp3"),
        },
    }


def is_enabled(mode: str = "openai") -> bool:
    """True when the chosen provider is ready to use.
    edge_tts and mms_tts need no API key so they are always enabled."""
    if mode in ("edge_tts", "mms_tts"):
        return True
    if mode == "voicebox":
        return bool(
            os.getenv("VOICEBOX_PROFILE_ID_FEMALE") or os.getenv("VOICEBOX_PROFILE_ID")
        )
    cfg = _providers().get(mode)
    return bool(cfg and cfg["api_key"])


def _clean(text: str) -> str:
    """Strip delivery cues the model leaves in the script (e.g. the message's
    [pause] markers) so the narrator reads the words, not the stage directions."""
    text = re.sub(r"\[[^\]]*\]", " ", text)  # [pause] and any other [cue]
    return re.sub(r"\s+", " ", text).strip()


def _mms_lang(language: str) -> str | None:
    """Map a service language to the MMS-TTS lang key, or None if unsupported."""
    return {"my": "burmese", "td": "tedim"}.get(language)


def _spell_tedim(n: int) -> str:
    if n == 0:
        return "nul"
    d = ["", "khat", "nih", "thum", "li", "nga", "guk", "sagih", "giat", "kua"]
    if n < 10:
        return d[n]
    if n < 100:
        tens, ones = divmod(n, 10)
        res = "sawm" if tens == 1 else "sawm " + d[tens]
        if ones > 0:
            res += " le " + d[ones]
        return res
    if n < 1000:
        hundreds, rem = divmod(n, 100)
        res = "za " + d[hundreds]
        if rem > 0:
            res += " le " + _spell_tedim(rem)
        return res
    return " ".join((d[int(x)] or "nul") for x in str(n))


def _spell_burmese(n: int) -> str:
    if n == 0:
        return "သုည"
    d = ["", "တစ်", "နှစ်", "သုံး", "လေး", "ငါး", "ခြောက်", "ခုနစ်", "ရှစ်", "ကိုး"]
    if n < 10:
        return d[n]
    if n < 100:
        tens, ones = divmod(n, 10)
        res = "ဆယ်" if tens == 1 else d[tens] + "ဆယ်"
        if ones > 0:
            res = res.replace("ဆယ်", "ဆယ့်") + d[ones]
        return res
    if n < 1000:
        hundreds, rem = divmod(n, 100)
        res = d[hundreds] + "ရာ"
        if rem > 0:
            res = res.replace("ရာ", "ရာ့") + _spell_burmese(rem)
        return res
    return "".join((d[int(x)] or "သုည") for x in str(n))


def _normalize_mms_text(text: str, language: str) -> str:
    """Convert digits to spoken words so MMS-TTS can pronounce them.

    Handles Arabic numerals (0-9), Burmese digits (၀-၉), and verse separators
    (colon or Burmese visarga း) so that e.g. "ယောဟန် ၃း၁၆" becomes
    "ယောဟန် သုံး ဆယ့်ခြောက်" rather than silence."""
    my_digits = "၀၁၂၃၄၅၆၇၈၉"
    for i, digit in enumerate(my_digits):
        text = text.replace(digit, str(i))
    # Split verse-style separators (3:16 or ၃း၁၆) into two independent numbers.
    text = re.sub(r'(?<=\d)[:း](?=\d)', ' ', text)

    def _repl(match: re.Match) -> str:
        val = int(match.group(0))
        word = _spell_burmese(val) if language == "my" else _spell_tedim(val)
        return f" {word} "

    text = re.sub(r'\d+', _repl, text)
    return re.sub(r'\s+', ' ', text).strip()


def _chunks(text: str) -> list[str]:
    """Split `text` into <= _MAX_CHARS pieces, preferring sentence boundaries."""
    if len(text) <= _MAX_CHARS:
        return [text]
    parts: list[str] = []
    buf = ""
    for sentence in re.split(r"(?<=[.!?])\s+", text):
        if buf and len(buf) + len(sentence) + 1 > _MAX_CHARS:
            parts.append(buf)
            buf = ""
        # A single sentence longer than the cap is hard-split as a last resort.
        while len(sentence) > _MAX_CHARS:
            parts.append(sentence[:_MAX_CHARS])
            sentence = sentence[_MAX_CHARS:]
        buf = f"{buf} {sentence}".strip()
    if buf:
        parts.append(buf)
    return parts


def _speak_mms(text: str, language: str) -> bytes:
    """Synthesize Myanmar/Tedim with the local MMS VITS service; return WAV bytes."""
    lang = _mms_lang(language)
    if not lang:
        raise RuntimeError(f"MMS TTS does not support language {language!r}")
    base_url = (os.getenv("MMS_SPEECH_URL") or os.getenv("MMS_TTS_URL", "http://127.0.0.1:8003")).rstrip("/")
    seed = int(os.getenv("MMS_TTS_SEED", "42"))
    resp = requests.post(
        f"{base_url}/tts/speak",
        json={"text": text, "lang": lang, "seed": seed},
        timeout=int(os.getenv("MMS_TTS_TIMEOUT", "180")),
    )
    resp.raise_for_status()
    return resp.content


def _speak(text: str, cfg: dict) -> bytes:
    """Synthesize a single (already size-bounded) chunk via an OpenAI-compatible
    /audio/speech endpoint; return the audio bytes."""
    resp = requests.post(
        f"{cfg['base_url']}/audio/speech",
        headers={
            "Authorization": f"Bearer {cfg['api_key']}",
            "Content-Type": "application/json",
        },
        json={
            "model": cfg["model"],
            "voice": cfg["voice"],
            "input": text,
            "response_format": cfg["fmt"],
        },
        timeout=60,
    )
    resp.raise_for_status()
    return resp.content


async def _speak_edge(text: str, voice: str) -> bytes:
    """Synthesize `text` with Microsoft Edge TTS; return mp3 bytes."""
    import edge_tts  # imported lazily so missing package only errors for this mode
    rate = os.getenv("EDGE_TTS_RATE", "")
    kwargs = {"rate": rate} if rate else {}
    communicate = edge_tts.Communicate(text, voice, **kwargs)
    audio = b""
    async for chunk in communicate.stream():
        if chunk["type"] == "audio":
            audio += chunk["data"]
    return audio


def _narrate_edge(text: str, voice: str) -> bytes:
    """Run the async Edge TTS synthesis from a sync context."""
    parts = _chunks(text)
    return b"".join(asyncio.run(_speak_edge(chunk, voice)) for chunk in parts if chunk)


def _voicebox_model_size(engine: str = "") -> str:
    """Map the admin's Voicebox choice onto the Docker image's Qwen model_size."""
    raw = (engine or os.getenv("VOICEBOX_ENGINE", "")).strip().lower()
    if raw in {"qwen_1_7b", "qwen-1.7b", "qwen-tts-1.7b", "1.7b"}:
        return "1.7B"
    if raw in {"qwen_0_6b", "qwen-0.6b", "qwen-tts-0.6b", "0.6b"}:
        return "0.6B"

    configured = os.getenv("VOICEBOX_MODEL_SIZE", "").strip()
    if configured in {"1.7B", "0.6B"}:
        return configured

    # The production box is CPU-only; 0.6B is the practical default.
    return "0.6B"


def _speak_voicebox(text: str, gender: str = "female", engine_override: str = "") -> bytes:
    """Synthesize text via local Voicebox /generate; return WAV bytes.

    engine_override lets the orchestrator pass the admin-chosen engine from the
    database setting (via the job payload) instead of the env var default.
    """
    base_url = os.getenv("VOICEBOX_URL", "http://127.0.0.1:17493").rstrip("/")
    env_key = "VOICEBOX_PROFILE_ID_MALE" if gender == "male" else "VOICEBOX_PROFILE_ID_FEMALE"
    profile_id = os.getenv(env_key) or os.getenv("VOICEBOX_PROFILE_ID")
    if not profile_id:
        raise RuntimeError(f"Voicebox profile not configured ({env_key} unset)")
    model_size = _voicebox_model_size(engine_override)
    timeout = int(os.getenv("VOICEBOX_TIMEOUT", "180"))

    resp = requests.post(
        f"{base_url}/generate",
        json={
            "profile_id": profile_id,
            "text": text,
            "language": "en",
            "model_size": model_size,
        },
        timeout=timeout,
    )
    if resp.status_code == 202:
        detail = resp.json().get("detail", {})
        message = detail.get("message") if isinstance(detail, dict) else str(detail)
        raise RuntimeError(message or f"Voicebox model {model_size} is still downloading")
    resp.raise_for_status()

    generation_id = resp.json().get("id")
    if not generation_id:
        raise RuntimeError("Voicebox did not return a generation id")

    audio_resp = requests.get(f"{base_url}/audio/{generation_id}", timeout=timeout)
    audio_resp.raise_for_status()
    return audio_resp.content  # WAV bytes


def narrate(
    session_token: str,
    segment: str,
    text: str,
    mode: str = "openai",
    voice: str = "",
    gender: str = "female",
    language: str = "en",
) -> str:
    """Read `text` aloud with the `mode` provider, store the audio, and return a
    playable URL.

    Raises on any failure — the caller logs and the segment stays text-only."""
    clean = _clean(text)

    if mode == "mms_tts":
        if not _mms_lang(language):
            raise RuntimeError(f"mms_tts does not support language {language!r}")
        # Normalize numbers to text before passing to the acoustic model
        clean = _normalize_mms_text(clean, language)
        audio = _speak_mms(clean, language)
        fmt = "wav"
    elif mode == "edge_tts":
        # Real Microsoft Edge TTS for all languages:
        #   English → EDGE_TTS_VOICE_FEMALE/MALE env vars
        #   Myanmar → EDGE_TTS_VOICE_MY_FEMALE/MALE (my-MM-NilarNeural/ThihaNeural)
        #   Tedim   → EDGE_TTS_VOICE_TD (no native Zolai voice; English phonetic read)
        # `voice` is pre-resolved by the orchestrator's _edge_voice(); only fall back
        # to generic env vars when it arrives empty.
        suffix = gender.upper()
        resolved_voice = (voice
                          or os.getenv(f"EDGE_TTS_VOICE_{suffix}")
                          or os.getenv("EDGE_TTS_VOICE", "en-US-AriaNeural"))
        audio = _narrate_edge(clean, resolved_voice)
        fmt = "mp3"
    elif mode == "voicebox":
        if language in ("my", "td"):
            # Voicebox only synthesises English; route native languages to MMS-TTS
            # so Myanmar/Tedim text is read by the correct VITS voice, not garbled
            # through an English model that cannot produce those scripts.
            if not _mms_lang(language):
                raise RuntimeError(f"mms_tts does not support language {language!r}")
            clean = _normalize_mms_text(clean, language)
            audio = _speak_mms(clean, language)
        else:
            audio = b"".join(
                _speak_voicebox(chunk, gender=gender, engine_override=voice)
                for chunk in _chunks(clean)
                if chunk
            )
        fmt = "wav"
    else:
        cfg = _providers(gender=gender).get(mode)
        if not cfg or not cfg["api_key"]:
            raise RuntimeError(f"narration provider {mode!r} is not configured")
        audio = b"".join(_speak(chunk, cfg) for chunk in _chunks(clean) if chunk)
        fmt = cfg["fmt"]

    content_type = "audio/mpeg" if fmt == "mp3" else f"audio/{fmt}"
    key = f"narration/{session_token}/{segment}.{fmt}"
    storage.upload_bytes(key, audio, content_type)
    # Consistent with avatar.render(): hand back a presigned, directly-playable URL.
    return storage.presign(key, expires=6 * 3600)
