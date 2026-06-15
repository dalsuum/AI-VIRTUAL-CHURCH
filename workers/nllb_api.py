"""
Dedicated NLLB translation FastAPI app — port 8004.

Runs independently from the Tedim (8001) and Burmese (8002) Ollama LLM services
and the MMS speech app (8003) so that PyTorch translation inference does not
compete with Ollama for the same CPU cores.

Run:
    uvicorn nllb_api:app --host 127.0.0.1 --port 8004 --workers 1
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fastapi import FastAPI
from nllb_service import router as nllb_router

app = FastAPI(
    title="AI Church — NLLB Translation",
    description="Local Burmese translation (English → Myanmar) via NLLB-200.",
    version="1.0.0",
)

app.include_router(nllb_router)


@app.get("/health")
async def health():
    return {"status": "ok"}
