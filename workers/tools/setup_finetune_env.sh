#!/bin/bash
#
# Prepares the Python virtual environment for fine-tuning by installing
# the necessary GPU-accelerated libraries. Run this once on your GPU
# server before your first training run.
#
# Usage:
#   bash workers/tools/setup_finetune_env.sh

set -e
cd "$(dirname "$0")/.." # move to workers/

VENV_PATH=".venv"
if [ ! -d "$VENV_PATH" ]; then
    echo "Virtual environment not found at $VENV_PATH. Please run 'python3 -m venv .venv' first."
    exit 1
fi

source "$VENV_PATH/bin/activate"

echo "Installing fine-tuning dependencies (unsloth, trl, peft, datasets)..."
pip install "unsloth[colab-new] @ git+https://github.com/unsloth/unsloth.git"
pip install "trl<0.9.0" peft accelerate bitsandbytes datasets

echo "✅ Fine-tuning environment is ready."