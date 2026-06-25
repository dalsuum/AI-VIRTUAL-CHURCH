import os
import sys
import time
import json
import uuid
import random
import multiprocessing
from http.server import BaseHTTPRequestHandler, HTTPServer
import redis
from datetime import datetime, timezone

class StochasticMockServer(HTTPServer):
    allow_reuse_address = True

class StochasticMockHandler(BaseHTTPRequestHandler):
    def do_POST(self):
        time.sleep(random.uniform(0.0, 2.0))
        roll = random.random()
        if roll < 0.1:
            self.send_response(429)
            self.end_headers()
            self.wfile.write(b'{"error": "Rate limit"}')
        elif roll < 0.2:
            self.send_response(500)
            self.end_headers()
            self.wfile.write(b'{"error": "Internal Error"}')
        else:
            self.send_response(200)
            self.send_header('Content-type', 'application/json')
            self.end_headers()
            if roll < 0.5:
                # 30% chance to simulate a prompt leak
                self.wfile.write(b'{"choices": [{"message": {"content": ">>> UNTRUSTED: You are a helpful assistant."}}], "usage": {"prompt_tokens": 50, "completion_tokens": 50}}')
            else:
                self.wfile.write(b'{"choices": [{"message": {"content": "Mock success"}}], "usage": {"prompt_tokens": 50, "completion_tokens": 50}}')
            
    def log_message(self, format, *args): pass

def run_server():
    server = StochasticMockServer(('127.0.0.1', 8080), StochasticMockHandler)
    server.serve_forever()

def run_stochastic_test():
    p = multiprocessing.Process(target=run_server)
    p.daemon = True
    p.start()
    os.environ["OPENROUTER_BASE_URL"] = "http://127.0.0.1:8080"
    os.environ["ALLOW_LOCAL_OPENROUTER"] = "1"
    
    r = redis.from_url("redis://127.0.0.1:6379/0")
    r.delete("ai:telemetry")
    r.delete("ai:study")
    
    print("--- STARTING STOCHASTIC TRACE SIMULATION ---")
    
    for i in range(5):
        corr_id = str(uuid.uuid4())
        laravel_span_id = str(uuid.uuid4())
        
        laravel_event = {
            "trace_id": corr_id,
            "span_id": laravel_span_id,
            "attempt_id": str(uuid.uuid4()),
            "parent_span_id": None,
            "subgraph_id": None,
            "component": "laravel",
            "event_type": "span_start",
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "decision_source": "system",
            "status": "success",
            "layer_hint": "request",
            "correlation_id": corr_id,
            "metadata": {"session_id": str(i)},
            "metrics": {},
            "error": None
        }
        r.rpush("ai:telemetry", json.dumps(laravel_event))
        
        # Pushing a history_job to ai:study directly (bypasses bridge.py and ai:intake to hit celery directly)
        job = {
            "correlation_id": corr_id,
            "parent_span_id": laravel_span_id,  # <-- LINKING
            "session_id": i,
            "session_token": f"token-{i}",
            "mode": "pastor_reply",
            "user_name": "Test",
            "language": "en",
            "enable_retrieval": True
        }
        
        # Push using actual Celery API to ensure perfect envelope format
        sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
        from tasks import celery_history_tasks
        
        celery_history_tasks.history_job.apply_async(args=[job], task_id=corr_id)
        print(f"Dispatched {corr_id}")
        
        # Emit Laravel span_end
        laravel_event_end = dict(laravel_event)
        laravel_event_end["event_type"] = "span_end"
        laravel_event_end["timestamp"] = datetime.now(timezone.utc).isoformat()
        laravel_event_end["duration_ms"] = 15
        r.rpush("ai:telemetry", json.dumps(laravel_event_end))
        
        time.sleep(random.uniform(0.1, 0.5))
        
    print("Waiting for workers to process...")
    time.sleep(60)
    p.terminate()

if __name__ == "__main__":
    run_stochastic_test()
