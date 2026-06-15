"""Local AI music strategy — GPU-preferred MusicGen for on-server worship generation.

Identical pipeline to MusicGenStrategy but optimized for GPU use:
  - MUSICGEN_DEVICE=auto picks CUDA when a GPU is present; falls back to CPU.
  - MUSICGEN_MODEL can be upgraded to facebook/musicgen-medium or
    facebook/musicgen-large on a GPU server for higher-quality output.
  - On CPU this behaves exactly like the `musicgen` source.
"""

from __future__ import annotations

from .musicgen_strategy import MusicGenStrategy
from . import MusicResult


class LocalAiStrategy(MusicGenStrategy):
    """Generate worship music locally; GPU-preferred, CPU-capable fallback."""

    def _generate(self, text: str, full_prompt: str) -> MusicResult:
        result = super()._generate(text, full_prompt)
        if result.title:
            result.title = result.title.replace("AI Worship", "Local AI Worship", 1)
        return result
