import os
import sys
import time
import json
import uuid
import multiprocessing
from http.server import BaseHTTPRequestHandler, HTTPServer
import requests

# Mock Server that injects chaos (429, 500)
class ChaosHandler(BaseHTTPRequestHandler):
    request_count = 0
    
    def do_POST(self):
        ChaosHandler.request_count += 1
        
        # Scenario 1: First 2 requests return 429 (Rate Limit) -> test Tenacity retries
        if ChaosHandler.request_count <= 2:
            self.send_response(429)
            self.end_headers()
            self.wfile.write(b'{"error": "Rate limit exceeded"}')
            return
            
        # Scenario 2: Next 3 requests return 500 (Provider Outage) -> test Circuit Breaker trip
        if ChaosHandler.request_count <= 5:
            self.send_response(500)
            self.end_headers()
            self.wfile.write(b'{"error": "Internal Server Error"}')
            return
            
        # Scenario 3: Success after timeout
        self.send_response(200)
        self.send_header('Content-type', 'application/json')
        self.end_headers()
        self.wfile.write(b'{"choices": [{"message": {"content": "Mock success"}}], "usage": {"total_tokens": 100}}')

def run_server():
    server = HTTPServer(('127.0.0.1', 8080), ChaosHandler)
    server.serve_forever()

def run_chaos_test():
    # 1. Start mock server in background
    p = multiprocessing.Process(target=run_server)
    p.daemon = True
    p.start()
    
    time.sleep(1) # wait for server
    
    # 2. Configure Gateway to hit the mock server
    sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    from core.ai_gateway import generate_response
    
    os.environ["OPENROUTER_BASE_URL"] = "http://127.0.0.1:8080"
    os.environ["ALLOW_LOCAL_OPENROUTER"] = "1"
    os.environ["GATEWAY_TIMEOUT"] = "1"
    
    print("--- STARTING CHAOS SCENARIOS ---")
    
    # Send requests to trigger the logic
    for i in range(6):
        print(f"\n[Request {i+1}]")
        try:
            generate_response(
                model="test-model",
                system_prompt="system",
                messages=[{"role": "user", "content": "hello"}],
                prompt_version="v1.0.0"
            )
            print("-> Success!")
        except Exception as e:
            print(f"-> Failed: {e}")
        
        time.sleep(1)
        
    p.terminate()
    print("\n--- CHAOS SCENARIOS COMPLETE ---")

if __name__ == "__main__":
    run_chaos_test()
