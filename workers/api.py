"""
Tedim LLM HTTP API
==================
Minimal FastAPI application that exposes the Tedim localization endpoints.
The workers/bridge.py Redis consumer and Celery tasks run separately; this
process only handles synchronous HTTP requests from Laravel and Celery tasks.

Run alongside the existing worker processes:
    uvicorn api:app --host 127.0.0.1 --port 8001 --workers 1

One worker is deliberate: the Ollama semaphore in tedim_router.py enforces
one concurrent inference, so extra uvicorn workers would just queue anyway
and waste memory on the 4-OCPU OCI ARM box.

TEDIM_LLM_URL must point here in both workers/.env and backend/.env:
    TEDIM_LLM_URL=http://127.0.0.1:8001
"""

import os
import sys

# Ensure workers/ directory is on sys.path so routers can import bible_api.
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fastapi import FastAPI
from mms_asr_service import router as mms_asr_router
from bible_router import router as bible_router
from burmese_router import router as burmese_router
from mms_tts_service import router as mms_tts_router
from tedim_router import router as tedim_router

app = FastAPI(
    title="AI Church — Language LLM + Speech API",
    description="Wraps local Ollama models and MMS speech routes for the worship pipeline.",
    version="1.2.0",
)

app.include_router(tedim_router)
app.include_router(bible_router)
app.include_router(burmese_router)
app.include_router(mms_tts_router)
app.include_router(mms_asr_router)


@app.get("/health")
async def health():
    return {"status": "ok"}
