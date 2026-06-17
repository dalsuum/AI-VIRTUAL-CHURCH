# Wav2Lip RunPod serverless worker (fast lip-sync avatar)

Drop-in replacement for the [SadTalker worker](../runpod_avatar/README.md). Same
input/output contract (`{image_b64, audio_b64}` → `{video_b64}`), so the
`avatar_proxy`, Celery worker, and frontend are **unchanged** — you only point the
endpoint at this image.

## Why Wav2Lip instead of SadTalker

SadTalker reconstructs the whole head in 3D per frame → ~5-7× real-time, so a
multi-minute sermon can't finish inside the worker's 300s budget (measured: a 30s
clip took ~2.5 min on an RTX A4500). Wav2Lip only animates the mouth region → near
real-time, which makes full-length sermons feasible on the same GPU.

| | SadTalker | Wav2Lip |
|---|---|---|
| 30s clip | ~2.5 min | ~15-30s |
| 3-min sermon | ~15 min ❌ | ~2-4 min ✅ |
| Motion | full head + expression | lips only, head mostly still |

## Build & push

```bash
cd workers/runpod_wav2lip
docker build -t <dockerhub-user>/wav2lip-runpod:latest .
docker push <dockerhub-user>/wav2lip-runpod:latest
```

The checkpoints (~450 MB) are baked in from the justinjohn0306 fork's release
assets. If those links ever move, override them:

```bash
docker build \
  --build-arg WAV2LIP_CKPT_URL=<url-to-wav2lip_gan.pth> \
  --build-arg S3FD_URL=<url-to-s3fd.pth> \
  -t <dockerhub-user>/wav2lip-runpod:latest .
```

## Point the endpoint at it

In the RunPod console, on your avatar endpoint (`5arz4413mbzhdx`) → edit the
container image to `<dockerhub-user>/wav2lip-runpod:latest` and release/refresh so
workers pull the new `:latest`. Keep FlashBoot on. The A4500/Ada GPUs you already
have are plenty — Wav2Lip needs far less than SadTalker.

No droplet changes: `RUNPOD_AVATAR_BASE_URL` already points at this endpoint, so
once the image is swapped the proxy uses Wav2Lip automatically.

## Request / response

```json
// POST https://api.runpod.ai/v2/<id>/run   (Bearer RUNPOD_API_KEY)
{"input": {"image_b64": "<base64 jpg>", "audio_b64": "<base64 wav>"}}
```

```json
{"output": {"video_b64": "<base64 mp4>"}}
```
