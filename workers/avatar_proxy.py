"""Thin avatar proxy: bridges the Celery worker's local-avatar contract to a
RunPod serverless talking-head endpoint (see runpod_avatar/).

The worker's avatar.py local path POSTs multipart/form-data (`image` + `audio`
files) to LOCAL_AVATAR_URL and expects raw MP4 bytes back. RunPod serverless,
however, speaks JSON with base64 payloads. This service is the adapter:

    avatar.py  --(multipart image+audio)-->  avatar_proxy  --(JSON base64)-->  RunPod
    avatar.py  <------(raw MP4 bytes)-------  avatar_proxy  <--(base64 MP4)----  RunPod

Run it on the droplet (CPU is fine — it only forwards) and point the worker at it:
    LOCAL_AVATAR_URL=http://127.0.0.1:8005/generate

Env:
    RUNPOD_API_KEY          shared with the LLM/NLLB endpoints
    RUNPOD_AVATAR_BASE_URL  https://api.runpod.ai/v2/<avatar-endpoint-id>
    AVATAR_PROXY_PORT       default 8005
    AVATAR_POLL_TIMEOUT     overall budget in seconds (default 280, under avatar.py's 300)
"""
import base64
import os
import time

import requests
from fastapi import FastAPI, File, HTTPException, UploadFile, Response

RUNPOD_API_KEY = os.getenv("RUNPOD_API_KEY", "")
RUNPOD_AVATAR_BASE_URL = os.getenv("RUNPOD_AVATAR_BASE_URL", "").rstrip("/")
POLL_TIMEOUT = int(os.getenv("AVATAR_POLL_TIMEOUT", "280"))
POLL_INTERVAL = float(os.getenv("AVATAR_POLL_INTERVAL", "3"))

app = FastAPI(title="AI Church avatar proxy")


def _headers() -> dict:
    return {"Authorization": f"Bearer {RUNPOD_API_KEY}", "Content-Type": "application/json"}


@app.get("/health")
def health() -> dict:
    return {"ok": True, "configured": bool(RUNPOD_API_KEY and RUNPOD_AVATAR_BASE_URL)}


# NOTE: deliberately a *sync* def. The body does blocking I/O (requests + time.sleep
# while polling RunPod). As an `async def` those blocking calls freeze the event loop,
# so concurrent segment renders (prayer/sermon/benediction all fire at once) serialize
# and time out. A sync path operation runs in FastAPI's threadpool instead, giving real
# concurrency and never stalling the loop.
@app.post("/generate")
def generate(image: UploadFile = File(...), audio: UploadFile = File(...)) -> Response:
    if not (RUNPOD_API_KEY and RUNPOD_AVATAR_BASE_URL):
        raise HTTPException(503, "RunPod avatar endpoint is not configured")

    image_b64 = base64.b64encode(image.file.read()).decode("ascii")
    audio_b64 = base64.b64encode(audio.file.read()).decode("ascii")
    payload = {"input": {"image_b64": image_b64, "audio_b64": audio_b64}}

    # Async submit + poll: cold starts can take a while, and a synchronous /runsync
    # may exceed RunPod's wait window. /run returns immediately with a job id.
    try:
        submit = requests.post(f"{RUNPOD_AVATAR_BASE_URL}/run", json=payload,
                               headers=_headers(), timeout=30)
        submit.raise_for_status()
        job_id = submit.json().get("id")
    except requests.RequestException as exc:
        raise HTTPException(502, f"RunPod submit failed: {exc}") from exc
    if not job_id:
        raise HTTPException(502, f"RunPod returned no job id: {submit.text[:500]}")

    deadline = time.time() + POLL_TIMEOUT
    status_url = f"{RUNPOD_AVATAR_BASE_URL}/status/{job_id}"
    while time.time() < deadline:
        time.sleep(POLL_INTERVAL)
        try:
            res = requests.get(status_url, headers=_headers(), timeout=30)
            res.raise_for_status()
            data = res.json()
        except requests.RequestException as exc:
            raise HTTPException(502, f"RunPod status poll failed: {exc}") from exc

        status = data.get("status")
        if status == "COMPLETED":
            output = data.get("output") or {}
            err = output.get("error")
            if err:
                raise HTTPException(502, f"avatar render error: {err}")
            video_b64 = output.get("video_b64")
            if not video_b64:
                raise HTTPException(502, "RunPod completed without a video payload")
            return Response(content=base64.b64decode(video_b64), media_type="video/mp4")
        if status in ("FAILED", "CANCELLED", "TIMED_OUT"):
            raise HTTPException(502, f"RunPod job {status}: {data}")
        # IN_QUEUE / IN_PROGRESS -> keep polling

    # Best-effort cancel so a slow job doesn't keep burning GPU after we've given up.
    try:
        requests.post(f"{RUNPOD_AVATAR_BASE_URL}/cancel/{job_id}", headers=_headers(), timeout=10)
    except requests.RequestException:
        pass
    raise HTTPException(504, f"avatar render timed out after {POLL_TIMEOUT}s")


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="127.0.0.1", port=int(os.getenv("AVATAR_PROXY_PORT", "8005")))
