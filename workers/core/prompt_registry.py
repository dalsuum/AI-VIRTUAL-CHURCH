"""Prompt Registry (Phase 2A)

Manages all system prompts and instructions centrally.
Allows versioning, easy updates, and decoupling prompts from execution logic.
"""

from typing import Optional

import os
import yaml
from typing import Optional

_REGISTRY_PATH = os.path.join(os.path.dirname(__file__), "prompts.yaml")
_REGISTRY: dict = {}

def _load_registry():
    global _REGISTRY
    if not _REGISTRY and os.path.exists(_REGISTRY_PATH):
        with open(_REGISTRY_PATH, "r", encoding="utf-8") as f:
            data = yaml.safe_load(f)
            if data and "features" in data:
                _REGISTRY = data["features"]

def get_prompt(feature_id: str, version: Optional[str] = None, default: Optional[str] = None) -> tuple[str, str]:
    """Retrieve a versioned system prompt for a specific feature.
    
    Args:
        feature_id: The unique identifier (e.g., 'pastor_chat').
        version: Specific SemVer version string. If None, uses active_version.
        default: Fallback prompt if not found.
        
    Returns:
        (prompt_string, version_string)
    """
    _load_registry()
    feature = _REGISTRY.get(feature_id)
    
    if not feature:
        if default:
            return default, "fallback"
        raise ValueError(f"Prompt not found for feature: {feature_id}")
        
    target_version = version or feature.get("active_version")
    versions = feature.get("versions", {})
    
    prompt = versions.get(target_version)
    if not prompt:
        if default:
            return default, "fallback"
        raise ValueError(f"Version {target_version} not found for feature: {feature_id}")
        
    return prompt, target_version
