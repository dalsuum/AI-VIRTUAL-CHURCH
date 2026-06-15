import os
import sys
import unittest
from unittest.mock import patch

# Add the workers directory to sys.path so imports work correctly
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), '..')))

import agent_orchestrator


class TestAgentOrchestrator(unittest.TestCase):

    @patch("agent_orchestrator.llm_engine.build_intake_plan")
    @patch("agent_orchestrator._celery_app.send_task")
    @patch("agent_orchestrator._call_llm")
    @patch("agent_orchestrator._post_asset")
    def test_tool_call_json_decode_error_handled_safely(
        self, mock_post_asset, mock_call_llm, mock_send_task, mock_build_plan
    ):
        """Verify that malformed JSON in tool call arguments doesn't crash the agent loop."""
        
        # Mock the intake plan
        mock_build_plan.return_value = {
            "scripture_ref": "John 3:16", 
            "preaching_query": "sermon"
        }

        # Mock LLM to return tool calls with invalid JSON arguments
        mock_call_llm.return_value = {
            "usage": {"prompt_tokens": 10, "completion_tokens": 5},
            "choices": [
                {
                    "finish_reason": "tool_calls",
                    "message": {
                        "role": "assistant",
                        "content": "",
                        "tool_calls": [
                            {
                                "id": "call_123",
                                "type": "function",
                                "function": {
                                    "name": "generate_and_post_sermon",
                                    "arguments": "{ invalid json -> fallback to {} -> triggers safe default"
                                }
                            },
                            {
                                "id": "call_124",
                                "type": "function",
                                "function": {
                                    "name": "finish_service",
                                    "arguments": "{}"
                                }
                            }
                        ]
                    }
                }
            ]
        }

        job = {"session_token": "test-token", "language": "en", "mood": "Hopeful"}
        
        # This should execute without raising JSONDecodeError or TypeError
        agent_orchestrator.run_agent(job)
        
        # Verify the LLM was called and the loop processed the tool calls safely
        mock_call_llm.assert_called()

    @patch("agent_orchestrator._find_sermon_video")
    @patch("agent_orchestrator.llm_engine.build_intake_plan")
    @patch("agent_orchestrator._celery_app.send_task")
    @patch("agent_orchestrator._call_llm")
    @patch("agent_orchestrator._post_asset")
    def test_find_sermon_video_fallback(
        self, mock_post_asset, mock_call_llm, mock_send_task, mock_build_plan, mock_find_video
    ):
        """Verify find_and_post_sermon_video falls back to the plan's preaching_query when missing."""
        mock_build_plan.return_value = {
            "scripture_ref": "John 3:16", 
            "preaching_query": "specific fallback query"
        }
        mock_find_video.return_value = {"video_id": "vid123", "title": "Test Title"}

        # Mock the LLM returning a find_sermon_video tool call with empty arguments
        mock_call_llm.return_value = {
            "usage": {"prompt_tokens": 10, "completion_tokens": 5},
            "choices": [{
                "finish_reason": "tool_calls",
                "message": {
                    "role": "assistant",
                    "content": "",
                    "tool_calls": [
                        {"id": "call_1", "type": "function", "function": {"name": "find_and_post_sermon_video", "arguments": "{}"}},
                        {"id": "call_2", "type": "function", "function": {"name": "finish_service", "arguments": "{}"}}
                    ]
                }
            }]
        }

        job = {"session_token": "test-token", "language": "en", "mood": "Hopeful", "music_source": "youtube"}
        
        agent_orchestrator.run_agent(job)
        
        # Verify the underlying sermon fetch used the plan's query, not the empty string
        mock_find_video.assert_called_once_with(
            mood="Hopeful", query="specific fallback query", language="en", excluded_ids=[]
        )

if __name__ == "__main__":
    unittest.main()