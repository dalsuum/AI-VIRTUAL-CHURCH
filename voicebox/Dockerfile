FROM ghcr.io/jamiepine/voicebox:latest

RUN apt-get update \
    && apt-get install -y --no-install-recommends sox libsox-fmt-all \
    && rm -rf /var/lib/apt/lists/*

RUN python -m pip install --no-cache-dir qwen-tts==0.1.1 torchaudio==2.10.0
