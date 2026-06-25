import os
import time
import uuid
import requests
from typing import Any
from datetime import datetime, timedelta, timezone

# Standard timeout for external API calls
GATEWAY_TIMEOUT = int(os.getenv("GATEWAY_TIMEOUT", "120"))
DEFAULT_OPENROUTER_URL = "https://openrouter.ai/api/v1"

# Circuit Breaker state
_CIRCUIT_STATE = {
    "failures": 0,
    "tripped_until": None
}

def _should_retry(response) -> bool:
    return response.status_code in (429, 500, 502, 503, 504)


def _resolve_base_url(base_url: str | None) -> str:
    url = (base_url or os.getenv("OPENROUTER_BASE_URL", DEFAULT_OPENROUTER_URL)).rstrip("/")
    if (
        url in {"http://127.0.0.1:8080", "http://localhost:8080"}
        and os.getenv("ALLOW_LOCAL_OPENROUTER") != "1"
    ):
        print(
            "[gateway] Ignoring local OpenRouter chaos URL outside explicit test mode.",
            flush=True,
        )
        return DEFAULT_OPENROUTER_URL
    return url

def generate_response(
    model: str,
    system_prompt: str,
    messages: list[dict],
    base_url: str | None = None,
    api_key: str | None = None,
    temperature: float = 0.7,
    max_tokens: int = 1500,
    tools: list[dict] | None = None,
    prompt_version: str | None = None,
    enable_retrieval: bool = False,
    enable_evaluator: bool = False,
) -> tuple[str, dict]:
    """Unified generation method pointing to OpenAI-compatible endpoints with Retries and Circuit Breaker."""
    from core.telemetry import Span, TraceEvent, log_event, trace_id_var, span_id_var, attempt_id_var, parent_span_id_var, subgraph_id_var, correlation_id_var, RetrievalSpanFactory
    
    class RetrievalGraphBuilder:
        def __init__(self):
            self.factory = RetrievalSpanFactory()

        def execute(self, trace_id, parent_span_id, query):
            spans = []

            # Root retrieval span
            root = self.factory.create_root(trace_id, parent_span_id)
            subgraph_id = root["subgraph_id"]
            spans.append(root)

            # Step 1: Embedding
            emb = self.factory.create_embedding(trace_id, root["span_id"], subgraph_id)
            spans.append(emb)
            embedding_vector = self._embed(query)

            # Step 2: Vector search
            vec = self.factory.create_vector_search(trace_id, root["span_id"], subgraph_id)
            spans.append(vec)
            results = self._search(embedding_vector)

            # Step 3: Rerank
            rerank = self.factory.create_rerank(trace_id, root["span_id"], subgraph_id)
            spans.append(rerank)
            final_context = self._rerank(results)

            return {
                "spans": spans,
                "context": final_context,
                "subgraph_id": subgraph_id
            }

        def _embed(self, query):
            # existing embedding call
            return {"vector": [0.1, 0.2]}

        def _search(self, vector):
            # vector DB call (qdrant/milvus later)
            return [{"doc": "sample"}]

        def _rerank(self, results):
            return results[:3]

    class EvaluatorGraphBuilder:
        def __init__(self):
            from core.telemetry import EvaluatorSpanFactory
            self.factory = EvaluatorSpanFactory()

        def execute(self, trace_id, parent_span_id, response_text):
            from core.guardrails import validate_output
            spans = []

            # Root evaluator span
            root = self.factory.create_root(trace_id, parent_span_id)
            subgraph_id = root["subgraph_id"]
            spans.append(root)

            # Step 1: Safety/Injection check
            safety = self.factory.create_safety_check(trace_id, root["span_id"], subgraph_id)
            spans.append(safety)
            
            is_safe_out, reason_out = validate_output(response_text)
            
            if not is_safe_out:
                override = self.factory.create_override_decision(trace_id, root["span_id"], subgraph_id)
                spans.append(override)
                response_text = ""

            return {
                "spans": spans,
                "response_text": response_text,
                "subgraph_id": subgraph_id
            }
            
    # --- Integration Patch ---
    retrieval_result = None
    retrieval_spans = []
    
    # Grab context
    current_trace_id = trace_id_var.get() or "unknown"
    current_span_id = span_id_var.get()
    
    if enable_retrieval or os.getenv("ENABLE_RETRIEVAL") == "1":
        builder = RetrievalGraphBuilder()
        # Find the query (assume last message)
        query = messages[-1]["content"] if messages else ""
        result = builder.execute(
            trace_id=current_trace_id,
            parent_span_id=current_span_id,
            query=query
        )
        retrieval_result = result["context"]
        retrieval_spans = result["spans"]
        
        # Log all the generated subgraphs!
        for s in retrieval_spans:
            log_event(TraceEvent(**s))
            
    # Inject context if we have it
    if retrieval_result:
        system_prompt += f"\n\nContext:\n{retrieval_result}"
    
    # Circuit Breaker Check
    if _CIRCUIT_STATE["tripped_until"]:
        if datetime.utcnow() < _CIRCUIT_STATE["tripped_until"]:
            with Span(component="gateway", layer_hint="circuit_breaker", decision_source="system") as span:
                raise RuntimeError("Circuit breaker tripped.")
        else:
            # Reset after timeout
            _CIRCUIT_STATE["failures"] = 0
            _CIRCUIT_STATE["tripped_until"] = None
            
    url = _resolve_base_url(base_url)
    key = api_key or os.getenv("OPENROUTER_API_KEY", "")
    headers = {"Authorization": f"Bearer {key}", "Content-Type": "application/json"}
    
    payload_messages = [{"role": "system", "content": system_prompt}] + messages
    payload: dict[str, Any] = {
        "model": model,
        "messages": payload_messages,
        "temperature": temperature,
        "max_tokens": max_tokens,
    }
    if tools:
        payload["tools"] = tools

    max_retries = 2
    delay = 2
    
    # Keep the same logical span ID across retries
    gateway_span_id = str(uuid.uuid4())
    
    for attempt in range(max_retries + 1):
        with Span(
            component="gateway",
            layer_hint="ai_gateway",
            decision_source="llm",
            span_id=gateway_span_id,
            metadata={"model": model, "prompt_version": prompt_version, "retry_attempt": attempt}
        ) as span:
            try:
                resp = requests.post(f"{url}/chat/completions", headers=headers, json=payload, timeout=GATEWAY_TIMEOUT)
                
                if not resp.ok and _should_retry(resp) and attempt < max_retries:
                    # Let the span exit naturally (success) but we didn't raise, wait, we should raise to mark as error or just let it close as error
                    # Let's raise an internal exception so the span records it as an error
                    resp.raise_for_status()
                    
                resp.raise_for_status()
                data = resp.json()
                
                # Reset circuit breaker on success
                if _CIRCUIT_STATE["failures"] > 0:
                    _CIRCUIT_STATE["failures"] = 0
                
                usage = data.get("usage", {})
                usage["model"] = data.get("model", model)
                choices = data.get("choices", [])
                if not choices:
                    return "", usage
                    
                text = (choices[0]["message"].get("content", "") or "").strip()
                
                span.metrics["input_tokens"] = usage.get("prompt_tokens", 0)
                span.metrics["output_tokens"] = usage.get("completion_tokens", 0)
                
                # Phase 2B.2 Evaluator hook
                if enable_evaluator or os.getenv("ENABLE_EVALUATOR") == "1":
                    eval_builder = EvaluatorGraphBuilder()
                    eval_result = eval_builder.execute(
                        trace_id=current_trace_id,
                        parent_span_id=current_span_id,
                        response_text=text
                    )
                    text = eval_result["response_text"]
                    
                    for s in eval_result["spans"]:
                        log_event(TraceEvent(**s))
                        
                    if not text:
                        return "", usage
                
                return text, usage
                
            except requests.RequestException as e:
                # The Span context manager will record this exception and exit.
                if attempt < max_retries:
                    time.sleep(delay)
                    delay *= 2
                    continue
                    
                # Record failure for Circuit Breaker
                _CIRCUIT_STATE["failures"] += 1
                if _CIRCUIT_STATE["failures"] >= 3:
                    _CIRCUIT_STATE["tripped_until"] = datetime.utcnow() + timedelta(minutes=5)
                    
                raise RuntimeError(f"Gateway generation failed: {e}") from e
