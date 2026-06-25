"""
Knowledge embedding service
===========================
Exposes POST /knowledge/embed for the Laravel Knowledge Platform
(App\\Services\\Knowledge\\Embedding\\WorkerEmbeddingService). The embedding model runs HERE,
next to Ollama/FAISS; Laravel stays model-agnostic and only ships text in / vectors out.

Model: a small sentence-transformer (default all-MiniLM-L6-v2, 384-dim, ~80 MB) chosen to fit
a 2 GB box. Override with KNOWLEDGE_EMBED_MODEL. The model loads LAZILY on first request so the
process starts light and only pays the memory cost when embeddings are actually used.

Point Laravel at this app:
    KNOWLEDGE_EMBEDDING=worker
    KNOWLEDGE_WORKER_URL=http://127.0.0.1:8001   # whatever host:port api:app runs on
    KNOWLEDGE_EMBEDDING_DIMS=384                  # must match the model's output dim

Install once (in the workers venv):
    pip install sentence-transformers
"""

import os
import threading

from fastapi import APIRouter
from pydantic import BaseModel, Field

router = APIRouter()

_MODEL_NAME = os.environ.get("KNOWLEDGE_EMBED_MODEL", "all-MiniLM-L6-v2")
_model = None
_model_lock = threading.Lock()


def _get_model():
    """Lazy, thread-safe singleton load — keeps the process light until embeddings are used."""
    global _model
    if _model is None:
        with _model_lock:
            if _model is None:
                from sentence_transformers import SentenceTransformer

                _model = SentenceTransformer(_MODEL_NAME)
    return _model


class EmbedRequest(BaseModel):
    texts: list[str] = Field(default_factory=list)


@router.post("/knowledge/embed")
async def embed(req: EmbedRequest):
    if not req.texts:
        return {"vectors": [], "model": _MODEL_NAME, "dim": 0}

    model = _get_model()
    # normalize_embeddings=True → unit vectors, so cosine == dot product in the vector store.
    vectors = model.encode(
        req.texts,
        normalize_embeddings=True,
        convert_to_numpy=True,
    ).tolist()

    return {"vectors": vectors, "model": _MODEL_NAME, "dim": len(vectors[0]) if vectors else 0}
