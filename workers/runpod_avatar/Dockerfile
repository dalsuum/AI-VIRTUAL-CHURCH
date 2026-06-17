# RunPod serverless worker: SadTalker audio-driven talking-head avatar.
#
# SadTalker pins old torch/numpy, so we build on a CUDA 11.7 + torch 2.0.1 base
# that matches its tested setup. (Docker Hub removed the cuda11.8 variant of this
# tag; 11.7 is the available torch-2.0.1 build and works the same for SadTalker.)
# The repo and its ~2 GB of checkpoints are baked into the image so cold starts
# don't re-download them on every GPU spin-up.
FROM pytorch/pytorch:2.0.1-cuda11.7-cudnn8-runtime

ENV DEBIAN_FRONTEND=noninteractive \
    SADTALKER_DIR=/app/SadTalker

# System deps: ffmpeg for muxing audio+video, git to clone SadTalker,
# wget for SadTalker's download_models.sh, libs for OpenCV.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git wget ffmpeg libgl1 libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
RUN git clone --depth 1 https://github.com/OpenTalker/SadTalker.git

WORKDIR /app/SadTalker
# SadTalker's deps. Force a known-good numpy and add gfpgan/face libs it expects.
RUN python3 -m pip install --no-cache-dir -r requirements.txt \
    && python3 -m pip install --no-cache-dir runpod>=1.6.0

# Bake the model checkpoints (gfpgan + SadTalker) into the image.
RUN bash scripts/download_models.sh

COPY handler.py /app/SadTalker/handler.py

CMD ["python3", "-u", "/app/SadTalker/handler.py"]
