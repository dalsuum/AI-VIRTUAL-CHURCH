# RunPod serverless worker: Wav2Lip fast lip-sync avatar.
#
# We clone the justinjohn0306 Wav2Lip fork — it's patched for modern Python/Torch
# and ships reliable checkpoint download links as GitHub release assets (the
# original repo's Google Drive links can't be wget'd in a build). CUDA 12.1 so the
# image runs on Ampere (A4500), Ada (RTX 2000/4000 Ada) and newer cards alike.
FROM pytorch/pytorch:2.1.0-cuda12.1-cudnn8-runtime

ENV DEBIAN_FRONTEND=noninteractive \
    WAV2LIP_DIR=/app/Wav2Lip

RUN apt-get update && apt-get install -y --no-install-recommends \
        git wget ffmpeg libgl1 libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
RUN git clone --depth 1 https://github.com/justinjohn0306/Wav2Lip.git

WORKDIR /app/Wav2Lip
# Known-good combo: librosa 0.9.1 + numpy 1.23.5 avoids the librosa>=0.10
# positional-arg break in Wav2Lip's audio.py. opencv-headless (no GUI in a worker).
# batch_face (RetinaFace) is what this fork's inference.py uses for face detection.
RUN python3 -m pip install --no-cache-dir \
        numpy==1.23.5 librosa==0.9.1 numba opencv-python-headless tqdm \
        batch_face runpod>=1.6.0

# Checkpoints baked in so cold starts don't re-download. This fork's inference.py
# detects faces with RetinaFace and loads its weights from checkpoints/mobilenet.pth,
# so both the Wav2Lip GAN model and the mobilenet detector are required there.
# Override the URLs at build time (--build-arg) if these release assets ever move.
ARG WAV2LIP_CKPT_URL=https://github.com/justinjohn0306/Wav2Lip/releases/download/models/wav2lip_gan.pth
ARG MOBILENET_URL=https://github.com/justinjohn0306/Wav2Lip/releases/download/models/mobilenet.pth
RUN mkdir -p checkpoints \
    && wget -q -O checkpoints/wav2lip_gan.pth "$WAV2LIP_CKPT_URL" \
    && wget -q -O checkpoints/mobilenet.pth "$MOBILENET_URL"

COPY handler.py /app/Wav2Lip/handler.py

CMD ["python3", "-u", "/app/Wav2Lip/handler.py"]
