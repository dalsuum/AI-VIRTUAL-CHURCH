"""
Dedicated MMS speech FastAPI app — port 8003.

Runs independently from the Tedim (8001) and Burmese (8002) LLM services so
that PyTorch speech synthesis/transcription does not compete with Ollama
inference for the same CPU cores. Only the /tts/* and /stt/* routes are mounted
here.

Run:
    uvicorn mms_tts_api:app --host 127.0.0.1 --port 8003 --workers 1
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fastapi import FastAPI
from mms_asr_service import router as mms_asr_router
from mms_tts_service import router as mms_tts_router

app = FastAPI(
    title="AI Church — MMS Speech",
    description="Native Tedim/Burmese speech synthesis plus optional MMS ASR transcript checks.",
    version="1.1.0",
)

app.include_router(mms_tts_router)
app.include_router(mms_asr_router)


@app.get("/health")
async def health():
    return {"status": "ok"}
