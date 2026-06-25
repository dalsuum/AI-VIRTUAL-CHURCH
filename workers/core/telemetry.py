import json
import time
import os
import uuid
import traceback
from datetime import datetime, timezone
from contextvars import ContextVar
from dataclasses import dataclass, asdict
from typing import Any, Optional

# Optional Redis connection for centralized telemetry
import redis
_redis_client = None
try:
    _redis_client = redis.from_url(os.getenv("REDIS_URL", "redis://localhost:6379/0"))
except Exception:
    pass

# Global Context Variables for tracing
trace_id_var: ContextVar[Optional[str]] = ContextVar("trace_id", default=None)
correlation_id_var: ContextVar[Optional[str]] = ContextVar("correlation_id", default=None)
span_id_var: ContextVar[Optional[str]] = ContextVar("span_id", default=None)
attempt_id_var: ContextVar[Optional[str]] = ContextVar("attempt_id", default=None)
parent_span_id_var: ContextVar[Optional[str]] = ContextVar("parent_span_id", default=None)
subgraph_id_var: ContextVar[Optional[str]] = ContextVar("subgraph_id", default=None)
session_id_var: ContextVar[Optional[str]] = ContextVar("session_id", default=None)

def set_correlation_id(cid: str) -> None:
    correlation_id_var.set(cid)
    # Automatically map trace_id to correlation_id if not set otherwise
    if trace_id_var.get() is None:
        trace_id_var.set(cid)

def set_session_id(sid: str) -> None:
    session_id_var.set(sid)

@dataclass
class TraceEvent:
    trace_id: str
    span_id: str
    attempt_id: str

    parent_span_id: Optional[str]
    subgraph_id: Optional[str]

    component: str
    event_type: str

    timestamp: str
    duration_ms: Optional[int]

    correlation_id: str
    decision_source: Optional[str]

    status: str
    layer_hint: str

    metadata: dict
    metrics: dict
    error: Optional[dict]
    attribution_chain: Optional[list[str]] = None

def log_event(event: TraceEvent) -> None:
    """Emit a structured JSON log adhering to Trace Schema v1.0."""
    data = asdict(event)
    json_str = json.dumps(data)
    print(json_str, flush=True)
    
    if _redis_client:
        try:
            _redis_client.rpush("ai:telemetry", json_str)
        except Exception as e:
            print(f"[telemetry] Failed to push to redis: {e}", flush=True)

class Span:
    """
    Context manager to enforce Schema v1.0 tracing boundaries.
    Generates deterministic span_id per logical operation, but new attempt_id per execution.
    """
    def __init__(
        self,
        component: str,
        layer_hint: str,
        decision_source: Optional[str] = None,
        subgraph_id: Optional[str] = None,
        span_id: Optional[str] = None,
        metadata: Optional[dict] = None
    ):
        self.component = component
        self.layer_hint = layer_hint
        self.decision_source = decision_source
        self.subgraph_id_val = subgraph_id or subgraph_id_var.get()
        self.metadata = metadata or {}
        self.metrics = {}
        
        # Logical identity (can be provided externally for retries, or generated)
        self.span_id = span_id or str(uuid.uuid4())
        # Execution identity (always unique per execution instance)
        self.attempt_id = str(uuid.uuid4())
        
        self.parent_span_id = span_id_var.get()
        
    def __enter__(self):
        self._token_span = span_id_var.set(self.span_id)
        self._token_attempt = attempt_id_var.set(self.attempt_id)
        self._token_parent = parent_span_id_var.set(self.parent_span_id)
        if self.subgraph_id_val:
            self._token_subgraph = subgraph_id_var.set(self.subgraph_id_val)
            
        self.start_time = time.time()
        
        event = TraceEvent(
            trace_id=trace_id_var.get() or "unknown",
            span_id=self.span_id,
            attempt_id=self.attempt_id,
            parent_span_id=self.parent_span_id,
            subgraph_id=self.subgraph_id_val,
            component=self.component,
            event_type="span_start",
            timestamp=datetime.now(timezone.utc).isoformat(),
            duration_ms=None,
            correlation_id=correlation_id_var.get() or "unknown",
            decision_source=self.decision_source,
            status="success",
            layer_hint=self.layer_hint,
            metadata=self.metadata,
            metrics=self.metrics,
            error=None
        )
        log_event(event)
        return self
        
    def __exit__(self, exc_type, exc_val, exc_tb):
        duration_ms = int((time.time() - self.start_time) * 1000)
        
        status = "error" if exc_type else "success"
        event_type = "span_error" if exc_type else "span_end"
        
        error_dict = None
        if exc_type:
            error_dict = {
                "type": exc_type.__name__,
                "message": str(exc_val),
                "retryable": False,  # Downstream can override this logic later
                "stack_hash": str(hash(traceback.format_exc()))
            }
            
        event = TraceEvent(
            trace_id=trace_id_var.get() or "unknown",
            span_id=self.span_id,
            attempt_id=self.attempt_id,
            parent_span_id=self.parent_span_id,
            subgraph_id=self.subgraph_id_val,
            component=self.component,
            event_type=event_type,
            timestamp=datetime.now(timezone.utc).isoformat(),
            duration_ms=duration_ms,
            correlation_id=correlation_id_var.get() or "unknown",
            decision_source=self.decision_source,
            status=status,
            layer_hint=self.layer_hint,
            metadata=self.metadata,
            metrics=self.metrics,
            error=error_dict
        )
        log_event(event)
        
        span_id_var.reset(self._token_span)
        attempt_id_var.reset(self._token_attempt)
        parent_span_id_var.reset(self._token_parent)
        if self.subgraph_id_val:
            subgraph_id_var.reset(self._token_subgraph)


def now():
    return datetime.utcnow().isoformat()

class RetrievalSpanFactory:
    """
    Creates structured spans for Phase 2B retrieval graph.
    Each function represents a node in the reasoning subgraph.
    """

    def create_root(self, trace_id, parent_span_id):
        return self._span(
            trace_id=trace_id,
            span_id=str(uuid.uuid4()),
            parent_span_id=parent_span_id,
            component="retrieval",
            event_type="span_start",
            decision_source="retrieval",
            layer_hint="retrieval",
            subgraph_id=f"retrieval_{uuid.uuid4().hex[:8]}",
        )

    def create_embedding(self, trace_id, parent_span_id, subgraph_id):
        return self._span(
            trace_id=trace_id,
            span_id=str(uuid.uuid4()),
            parent_span_id=parent_span_id,
            component="retrieval",
            event_type="embedding_query",
            decision_source="retrieval",
            layer_hint="retrieval",
            subgraph_id=subgraph_id,
        )

    def create_vector_search(self, trace_id, parent_span_id, subgraph_id):
        return self._span(
            trace_id=trace_id,
            span_id=str(uuid.uuid4()),
            parent_span_id=parent_span_id,
            component="retrieval",
            event_type="vector_search",
            decision_source="retrieval",
            layer_hint="retrieval",
            subgraph_id=subgraph_id,
        )

    def create_rerank(self, trace_id, parent_span_id, subgraph_id):
        return self._span(
            trace_id=trace_id,
            span_id=str(uuid.uuid4()),
            parent_span_id=parent_span_id,
            component="retrieval",
            event_type="rerank",
            decision_source="retrieval",
            layer_hint="retrieval",
            subgraph_id=subgraph_id,
        )

    def _span(self, **kwargs):
        return {
            "trace_id": kwargs["trace_id"],
            "span_id": kwargs["span_id"],
            "attempt_id": str(uuid.uuid4()),
            "parent_span_id": kwargs["parent_span_id"],
            "subgraph_id": kwargs["subgraph_id"],
            "component": kwargs["component"],
            "event_type": kwargs["event_type"],
            "timestamp": now(),
            "duration_ms": None,
            "correlation_id": "unknown",  # Will be overridden by log_event logic or caller
            "decision_source": kwargs["decision_source"],
            "status": "success",
            "layer_hint": kwargs["layer_hint"],
            "metadata": {},
            "metrics": {},
            "error": None,
            "attribution_chain": ["retrieval"]
        }

class EvaluatorSpanFactory:
    """
    Creates structured spans for Phase 2B.2 evaluator graph.
    """

    def create_root(self, trace_id, parent_span_id):
        return self._span(
            trace_id=trace_id,
            span_id=str(uuid.uuid4()),
            parent_span_id=parent_span_id,
            component="evaluator",
            event_type="span_start",
            decision_source="evaluator",
            layer_hint="evaluation",
            subgraph_id=f"evaluator_{uuid.uuid4().hex[:8]}",
        )

    def create_safety_check(self, trace_id, parent_span_id, subgraph_id):
        return self._span(
            trace_id=trace_id,
            span_id=str(uuid.uuid4()),
            parent_span_id=parent_span_id,
            component="evaluator",
            event_type="evaluate_safety",
            decision_source="evaluator",
            layer_hint="evaluation",
            subgraph_id=subgraph_id,
        )

    def create_override_decision(self, trace_id, parent_span_id, subgraph_id):
        return self._span(
            trace_id=trace_id,
            span_id=str(uuid.uuid4()),
            parent_span_id=parent_span_id,
            component="evaluator",
            event_type="override_decision",
            decision_source="evaluator",
            layer_hint="evaluation",
            subgraph_id=subgraph_id,
        )

    def _span(self, **kwargs):
        return {
            "trace_id": kwargs["trace_id"],
            "span_id": kwargs["span_id"],
            "attempt_id": str(uuid.uuid4()),
            "parent_span_id": kwargs["parent_span_id"],
            "subgraph_id": kwargs["subgraph_id"],
            "component": kwargs["component"],
            "event_type": kwargs["event_type"],
            "timestamp": now(),
            "duration_ms": None,
            "correlation_id": "unknown",
            "decision_source": kwargs["decision_source"],
            "status": "success",
            "layer_hint": kwargs["layer_hint"],
            "metadata": {},
            "metrics": {},
            "error": None,
            "attribution_chain": ["llm", "evaluator"]
        }
