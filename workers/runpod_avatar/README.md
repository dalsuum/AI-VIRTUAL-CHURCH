# SadTalker RunPod serverless worker (talking-head avatar)

Audio-driven talking-head generation: a still portrait + narration audio → a
lip-synced MP4. Used by the local avatar engine via the `avatar_proxy` adapter.

## How it fits together

```
Celery worker (avatar.py, local path)
   │  multipart: image (jpg) + audio (wav)
   ▼
avatar_proxy.py  (on the droplet, CPU-only)   ← LOCAL_AVATAR_URL points here
   │  JSON: {"input": {"image_b64": ..., "audio_b64": ...}}   /run + poll /status
   ▼
RunPod serverless endpoint  (this image, GPU)
   │  {"output": {"video_b64": ...}}
   ▼
avatar_proxy → raw MP4 bytes → worker stores + posts as the segment's video
```

The worker does **not** call RunPod directly — RunPod speaks JSON/base64 while
`avatar.py` speaks multipart/MP4, so the proxy translates between them. This keeps
the worker code engine-agnostic.

## Build & push

```bash
cd workers/runpod_avatar
docker build -t <dockerhub-user>/sadtalker-runpod:latest .
docker push <dockerhub-user>/sadtalker-runpod:latest
```

> The build clones SadTalker and bakes ~2 GB of checkpoints into the image, so
> the first build is slow and the image is large (give it ≥ 15 GB container disk).

## Create the RunPod endpoint

In the RunPod console → **Serverless → Deploy a new endpoint → Deploy from a
Docker image** (or from this GitHub repo, pointing at `workers/runpod_avatar`):

- **Container image:** `<dockerhub-user>/sadtalker-runpod:latest`
- **GPU:** a 16–24 GB card (RTX 4090 / A4000 / L4) is plenty.
- **Container disk:** ≥ 15 GB (checkpoints are baked in).
- **Idle timeout / scale to zero:** keep low to save cost; expect a 20–90 s cold
  start on the first render after idle (the proxy's 280 s budget covers it).

Copy the endpoint id and set it on the droplet (see below).

> "Deploy LLM from Hugging Face" is **not** for this — that path is vLLM text
> LLMs only. Use the Docker-image (or GitHub) path.

## Request / response

```json
// POST https://api.runpod.ai/v2/<id>/run   (Bearer RUNPOD_API_KEY)
{"input": {"image_b64": "<base64 jpg>", "audio_b64": "<base64 wav>", "still": true}}
```

```json
{"output": {"video_b64": "<base64 mp4>"}}
```

## Droplet config (workers/.env)

```
RUNPOD_API_KEY=...                                   # already set (shared)
RUNPOD_AVATAR_BASE_URL=https://api.runpod.ai/v2/<avatar-endpoint-id>
AVATAR_PROXY_PORT=8005
LOCAL_AVATAR_URL=http://127.0.0.1:8005/generate
LOCAL_AVATAR_IMAGE_FEMALE=data/avatars/female_base.jpg
LOCAL_AVATAR_IMAGE_MALE=data/avatars/male_base.jpg
```

Then enable + start the proxy and turn on the local engine in the admin panel:

```bash
sudo cp .systemd/prod/aivc-avatar-proxy.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now aivc-avatar-proxy
sudo systemctl restart aivc-workers aivc-workers-orchestrate
curl -s http://127.0.0.1:8005/health
```
