"""D-ID Avatar rendering service for AI Virtual Church.

Generates a talking-head video for the given script using the D-ID Talks API,
waits for completion, downloads the resulting MP4, and saves it into local/S3 storage.
Alternatively, uses a local open-source avatar API (like LivePortrait) if configured.
"""

import os
import time
import requests
import storage
import logging

logger = logging.getLogger(__name__)

# Env vars are named DID_* in workers/.env. Keep the legacy D_ID_* names as a fallback
# so older deployments that used the underscored form keep working.
D_ID_API_KEY = os.getenv("DID_API_KEY") or os.getenv("D_ID_API_KEY", "")
D_ID_SOURCE_URL_FEMALE = (
    os.getenv("DID_SOURCE_URL_FEMALE")
    or os.getenv("D_ID_SOURCE_URL_FEMALE")
    or "https://create-images-results.d-id.com/DefaultPresenters/Noelle_f/image.jpeg"
)
D_ID_SOURCE_URL_MALE = (
    os.getenv("DID_SOURCE_URL_MALE")
    or os.getenv("D_ID_SOURCE_URL_MALE")
    or "https://create-images-results.d-id.com/DefaultPresenters/Matt_m/image.jpeg"
)
D_ID_VOICE_PROVIDER = os.getenv("DID_VOICE_PROVIDER", "microsoft")
D_ID_VOICE_ID_FEMALE = os.getenv("DID_VOICE_ID_FEMALE", "en-US-JennyNeural")
D_ID_VOICE_ID_MALE = os.getenv("DID_VOICE_ID_MALE", "en-US-GuyNeural")

LOCAL_AVATAR_URL = os.getenv("LOCAL_AVATAR_URL")
LOCAL_AVATAR_IMAGE_FEMALE = os.getenv("LOCAL_AVATAR_IMAGE_FEMALE", "data/avatars/female_base.jpg")
LOCAL_AVATAR_IMAGE_MALE = os.getenv("LOCAL_AVATAR_IMAGE_MALE", "data/avatars/male_base.jpg")

def is_enabled() -> bool:
    """Return True if the D-ID integration or local avatar generation is configured."""
    return bool(D_ID_API_KEY) or bool(LOCAL_AVATAR_URL)

def select_engine(did_enabled: bool = True, local_enabled: bool = False) -> str | None:
    """Resolve which avatar engine to use given the admin toggles and what's configured.

    The local open-source engine wins when it is both enabled and configured, otherwise
    D-ID is used when enabled and configured. Returns "local", "did", or None (skip)."""
    if local_enabled and LOCAL_AVATAR_URL:
        return "local"
    if did_enabled and D_ID_API_KEY:
        return "did"
    return None

def render(session_token: str, segment: str, script: str, gender: str = "female",
           audio_path: str = None, engine: str = "did") -> str:
    """Render a talking head video and return the presigned storage URL.

    `engine` selects the backend explicitly ("local" or "did") so the admin toggles, not
    just env-var presence, decide which is used when both are configured."""
    if engine == "local":
        if not LOCAL_AVATAR_URL:
            raise RuntimeError("Local avatar generation is not configured")
        if not audio_path or not os.path.exists(audio_path):
            # Raise (don't return None) so render_avatar's except degrades the segment to
            # text instead of posting a video asset with a null storage_key.
            raise RuntimeError(f"Local avatar generation requires a valid audio_path. Got: {audio_path}")

        base_image = LOCAL_AVATAR_IMAGE_MALE if gender == "male" else LOCAL_AVATAR_IMAGE_FEMALE

        if not os.path.exists(base_image):
            raise RuntimeError(f"Missing base image for avatar: {base_image}")

        with open(base_image, 'rb') as img_file, open(audio_path, 'rb') as audio_file:
            files = {
                'image': ('base.jpg', img_file, 'image/jpeg'),
                'audio': ('speech.wav', audio_file, 'audio/wav')
            }
            logger.info(f"Generating local open-source avatar for {gender} presenter...")
            
            response = requests.post(LOCAL_AVATAR_URL, files=files, timeout=300)
            response.raise_for_status()
            
            storage_key = f"avatar/{session_token}/{segment}.mp4"
            stored_key = storage.upload_bytes(storage_key, response.content, content_type="video/mp4")
            
            return storage.presign(stored_key, expires=604800)

    if not D_ID_API_KEY:
        raise RuntimeError("D-ID avatar generation is not configured")

    url = "https://api.d-id.com/talks"
    # The D-ID dashboard key is already in `base64(email):password` form and must be sent
    # verbatim after "Basic " — do NOT split it and let requests re-encode it (that would
    # base64 the value a second time and produce a 401).
    headers = {
        "Content-Type": "application/json",
        "Authorization": f"Basic {D_ID_API_KEY}",
    }

    source_url = D_ID_SOURCE_URL_MALE if gender == "male" else D_ID_SOURCE_URL_FEMALE
    voice_id = D_ID_VOICE_ID_MALE if gender == "male" else D_ID_VOICE_ID_FEMALE

    payload = {
        "source_url": source_url,
        "script": {
            "type": "text",
            "input": script,
            "provider": {
                "type": D_ID_VOICE_PROVIDER,
                "voice_id": voice_id
            }
        },
        "config": {
            "fluent": False,
            "pad_audio": 0.0
        }
    }

    # 1. Dispatch generation request
    response = requests.post(url, json=payload, headers=headers, timeout=30)
    response.raise_for_status()
    talk_id = response.json().get("id")
    if not talk_id:
        raise RuntimeError(f"Failed to create D-ID talk: {response.json()}")

    # 2. Poll for completion (up to 5 minutes)
    result_url = None
    for _ in range(60):
        time.sleep(5)
        res = requests.get(f"{url}/{talk_id}", headers=headers, timeout=10)
        res.raise_for_status()
        status = res.json().get("status")
        
        if status == "done":
            result_url = res.json().get("result_url")
            break
        elif status == "error":
            raise RuntimeError(f"D-ID generation failed: {res.json()}")
            
    if not result_url:
        raise RuntimeError("D-ID generation timed out after 5 minutes")

    # 3. Download the result video from S3 and host it locally so it won't expire
    vid_res = requests.get(result_url, timeout=60)
    vid_res.raise_for_status()
    
    storage_key = f"avatar/{session_token}/{segment}.mp4"
    stored_key = storage.upload_bytes(storage_key, vid_res.content, content_type="video/mp4")
    
    # Return the permanent presigned or local URL for the player Vue frontend
    return storage.presign(stored_key, expires=604800)