import redis
import json
import sys
import copy
from dataclasses import dataclass, field
from typing import Dict, List, Optional
from collections import defaultdict

class TraceNormalizer:
    """
    Phase 2A.7: Pre-DAG normalization layer.
    Responsible for:
    - retry reconciliation
    - orphan stitching
    - ordering stabilization
    - synthetic root generation
    """

    def normalize(self, events: List["TraceEvent"]) -> List["TraceEvent"]:
        events = self._sort_events(events)
        events = self._collapse_retries(events)
        events = self._stitch_legacy_roots(events)
        events = self._infer_missing_parents(events)
        return events

    def _sort_events(self, events):
        """
        Stable ordering:
        1. timestamp
        2. attempt_id (for retry ordering)
        3. component phase priority
        """
        phase_priority = {
            "laravel": 0,
            "celery": 1,
            "gateway": 2,
            "retrieval": 3,
            "evaluator": 4
        }
        def sort_key(e):
            return (
                e.trace_id,
                e.timestamp,
                e.attempt_id,
                phase_priority.get(e.component, 99)
            )
        return sorted(events, key=sort_key)

    def _collapse_retries(self, events):
        grouped = defaultdict(list)
        for e in events:
            key = (e.trace_id, e.span_id)
            grouped[key].append(e)

        normalized = []
        for (trace_id, span_id), group in grouped.items():
            group.sort(key=lambda x: x.timestamp)
            # keep logical continuity, preserve attempts
            base = copy.deepcopy(group[0])
            # Set attempt_chain on base (using metadata or dynamic attrib, but let's just use dynamic attrib to match reference)
            base.attempt_chain = [g.attempt_id for g in group]
            normalized.append(base)
        return normalized

    def _stitch_legacy_roots(self, events):
        """
        Handles:
        - missing parent_span_id
        - legacy jobs (study_discuss, history_job)
        """
        grouped = defaultdict(list)
        for e in events:
            grouped[e.trace_id].append(e)

        stitched = []
        for trace_id, group in grouped.items():
            has_root = any(e.parent_span_id is None and e.component in ["laravel", "system"] for e in group)
            if not has_root:
                # inject synthetic root
                synthetic_root = self._create_synthetic_root(trace_id, group)
                stitched.append(synthetic_root)
            stitched.extend(group)
        return stitched

    def _create_synthetic_root(self, trace_id, group):
        return TraceEvent(
            trace_id=trace_id,
            span_id=f"synthetic_root_{trace_id}",
            attempt_id="0",
            parent_span_id=None,
            subgraph_id=None,
            component="system",
            event_type="span_start",
            timestamp=min(e.timestamp for e in group),
            decision_source="system",
            status="success",
            metadata={"synthetic": True},
            metrics={},
            error=None
        )

    def _infer_missing_parents(self, events):
        """
        Infer parent_span_id ONLY when:
        - same trace_id
        - missing parent
        - temporal adjacency exists
        """
        by_trace = defaultdict(list)
        for e in events:
            by_trace[e.trace_id].append(e)

        for trace_id, group in by_trace.items():
            group.sort(key=lambda x: x.timestamp)
            for i in range(1, len(group)):
                current = group[i]
                previous = group[i - 1]
                if current.parent_span_id is None:
                    if self._is_valid_parent(previous, current):
                        current.parent_span_id = previous.span_id
        return events

    def _is_valid_parent(self, prev, curr):
        return (
            prev.component in ["laravel", "celery", "gateway", "system"] and
            prev.trace_id == curr.trace_id
        )

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

    decision_source: Optional[str]
    status: str

    correlation_id: Optional[str] = None
    metadata: Dict = field(default_factory=dict)
    metrics: Dict = field(default_factory=dict)
    error: Optional[Dict] = None
    duration_ms: Optional[int] = None
    attribution_chain: Optional[List[str]] = None
    layer_hint: Optional[str] = None

@dataclass
class SpanNode:
    span_id: str
    parent_span_id: Optional[str]
    subgraph_id: Optional[str]

    component: str
    decision_source: Optional[str]

    children: List["SpanNode"] = field(default_factory=list)
    attempts: List[TraceEvent] = field(default_factory=list)

def index_events(events: List[TraceEvent]):
    by_trace = defaultdict(list)
    by_span = defaultdict(list)

    for e in events:
        by_trace[e.trace_id].append(e)
        by_span[e.span_id].append(e)

    return by_trace, by_span

def build_dag(events: List[TraceEvent]) -> Dict[str, SpanNode]:
    nodes: Dict[str, SpanNode] = {}

    for e in events:
        if e.span_id not in nodes:
            nodes[e.span_id] = SpanNode(
                span_id=e.span_id,
                parent_span_id=e.parent_span_id,
                subgraph_id=e.subgraph_id,
                component=e.component,
                decision_source=e.decision_source
            )

    # build edges
    for node in nodes.values():
        if node.parent_span_id and node.parent_span_id in nodes:
            parent = nodes[node.parent_span_id]
            if node not in parent.children:
                parent.children.append(node)

    return nodes

def attach_attempts(events: List[TraceEvent], nodes: Dict[str, SpanNode]):
    for e in events:
        if e.span_id in nodes:
            nodes[e.span_id].attempts.append(e)

    # sort attempts chronologically
    for node in nodes.values():
        node.attempts.sort(key=lambda x: x.timestamp)

def group_subgraphs(nodes: Dict[str, SpanNode]):
    subgraphs = defaultdict(list)

    for node in nodes.values():
        key = node.subgraph_id or "main"
        subgraphs[key].append(node)

    return subgraphs

def detect_anomalies(nodes: Dict[str, SpanNode]):
    anomalies = []

    for node in nodes.values():
        # 1. missing completion (retrieval and evaluator subgraphs are instantaneous metrics for now)
        has_start = any(a.event_type == "span_start" for a in node.attempts)
        has_end = any(a.event_type in ["span_end", "span_error"] for a in node.attempts)

        if has_start and not has_end and node.component not in ["retrieval", "evaluator"]:
            anomalies.append({
                "type": "missing_span_end",
                "span_id": node.span_id
            })

        # 2. retry explosion (we count unique attempt_ids)
        unique_attempts = len(set(a.attempt_id for a in node.attempts))
        if unique_attempts > 5:
            anomalies.append({
                "type": "retry_spike",
                "span_id": node.span_id,
                "attempts": unique_attempts
            })

        # 3. orphan detection
        if node.parent_span_id is None and node.subgraph_id is None and node.component not in ["laravel", "system"]:
            anomalies.append({
                "type": "orphan_span",
                "span_id": node.span_id
            })

    return anomalies

def attach_reasoning_subgraphs(subgraphs: Dict[str, List[SpanNode]], nodes: Dict[str, SpanNode]):
    """
    Phase 2B: Attach reasoning subgraphs (Retrieval and Evaluator).
    Currently build_dag implicitly handles parent_span_id linkage,
    so this serves as a semantic annotation and grouping pass.
    """
    retrieval_graphs = {}
    evaluator_graphs = {}

    for sg_id, sg_nodes in subgraphs.items():
        if sg_id.startswith("retrieval_"):
            retrieval_graphs[sg_id] = sg_nodes
        elif sg_id.startswith("evaluator_"):
            evaluator_graphs[sg_id] = sg_nodes

    return retrieval_graphs, evaluator_graphs

def reconstruct_trace(events: List[TraceEvent]):
    normalizer = TraceNormalizer()

    # STEP 0: normalize BEFORE DAG
    normalized_events = normalizer.normalize(events)

    # STEP 1: DAG
    nodes = build_dag(normalized_events)

    # STEP 2: attempts
    attach_attempts(events, nodes)

    # STEP 3: subgraphs
    subgraphs = group_subgraphs(nodes)

    # STEP 4: reasoning graphs (Phase 2B)
    retrieval_graphs, evaluator_graphs = attach_reasoning_subgraphs(subgraphs, nodes)

    # STEP 5: anomalies (post-normalization)
    anomalies = detect_anomalies(nodes)

    return {
        "nodes": nodes,
        "subgraphs": subgraphs,
        "retrieval_graphs": retrieval_graphs,
        "evaluator_graphs": evaluator_graphs,
        "anomalies": anomalies
    }

def print_tree(node: SpanNode, indent="", is_last=True):
    marker = "└── " if is_last else "├── "
    
    # Check if there is a known attribution_chain from any attempt
    chain = None
    for a in node.attempts:
        if getattr(a, "attribution_chain", None):
            chain = a.attribution_chain
            break
            
    chain_str = f" [chain: {'>'.join(chain)}]" if chain else ""
    
    print(f"{indent}{marker}[{node.component}] {node.span_id} (attempts: {len(set(a.attempt_id for a in node.attempts))}){chain_str}")
    
    new_indent = indent + ("    " if is_last else "│   ")
    for i, child in enumerate(node.children):
        print_tree(child, new_indent, i == len(node.children) - 1)

def audit():
    r = redis.from_url("redis://127.0.0.1:6379/0")
    events_raw = r.lrange("ai:telemetry", 0, -1)
    
    if not events_raw:
        print("No telemetry events found.")
        return
        
    events = []
    for raw in events_raw:
        try:
            data = json.loads(raw)
            # Only process trace schema v1.0
            if "trace_id" in data:
                events.append(TraceEvent(**data))
        except Exception as e:
            pass
            
    if not events:
        print("No v1.0 traces found.")
        return
        
    by_trace, _ = index_events(events)
    
    total_anomalies = 0
    for trace_id, trace_events in by_trace.items():
        print(f"\n=== TRACE {trace_id} ===")
        res = reconstruct_trace(trace_events)
        
        # Print subgraphs
        for sg_name, nodes in res["subgraphs"].items():
            print(f"  Subgraph: {sg_name}")
            # find roots
            roots = [n for n in nodes if n.parent_span_id is None or n.parent_span_id not in res["nodes"]]
            for r_node in roots:
                print_tree(r_node, "    ", True)
                
        if res["anomalies"]:
            print("  [!] Anomalies Detected:")
            for a in res["anomalies"]:
                print(f"      - {a}")
            total_anomalies += len(res["anomalies"])
            
    print(f"\nTotal Anomalies: {total_anomalies}")
    if total_anomalies > 0:
        sys.exit(1)
        
if __name__ == "__main__":
    audit()
