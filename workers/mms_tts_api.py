"""
Dedicated MMS-TTS FastAPI app — port 8003.

Runs independently from the Tedim (8001) and Burmese (8002) LLM services so
that PyTorch speech synthesis does not compete with Ollama inference for the
same CPU cores.  Only the /tts/* routes are mounted here.

Run:
    uvicorn mms_tts_api:app --host 127.0.0.1 --port 8003 --workers 1
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fastapi import FastAPI
from mms_tts_service import router as mms_tts_router

app = FastAPI(
    title="AI Church — MMS TTS",
    description="Native Tedim (facebook/mms-tts-ctd) and Burmese (facebook/mms-tts-mya) speech synthesis.",
    version="1.0.0",
)

app.include_router(mms_tts_router)


@app.get("/health")
async def health():
    return {"status": "ok"}
