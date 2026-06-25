"""Context Builder (Phase 2A)

Centralizes retrieval and prompt assembly. Every feature (Pastor Chat, Sermon, Prayer)
should pass its retrieved documents and user input through this builder to ensure
consistent formatting and theological rules.
"""

from __future__ import annotations

def assemble_context(
    user_input: str,
    feature_type: str,
    retrieved_docs: list[dict] | None = None,
    history: list[dict] | None = None,
    system_prompt_base: str = "",
) -> tuple[str, list[dict]]:
    """Assemble the system prompt and conversation messages consistently.
    
    Args:
        user_input: The current user message or request.
        feature_type: e.g., 'pastor_chat', 'sermon', 'prayer'.
        retrieved_docs: List of dicts representing Bible verses, memories, etc.
        history: List of prior conversation dicts: [{"role": "user", "content": "..."}]
        system_prompt_base: The base instruction for the feature.
        
    Returns:
        (system_string, messages_list) suitable for the AI Gateway.
    """
    system_parts = [system_prompt_base]
    
    if retrieved_docs:
        system_parts.append("\n--- RETRIEVED CONTEXT ---")
        system_parts.append("Use the following context to inform your response. Do not invent details.")
        for doc in retrieved_docs:
            source = doc.get("source", "Unknown")
            content = doc.get("content", "")
            system_parts.append(f"[{source}]: {content}")
            
    system_string = "\n".join(system_parts)
    
    messages = []
    if history:
        for msg in history:
            # Ensure valid roles
            if msg.get("role") in ("user", "assistant"):
                messages.append(msg)
                
    if user_input:
        messages.append({"role": "user", "content": user_input})
        
    return system_string, messages
