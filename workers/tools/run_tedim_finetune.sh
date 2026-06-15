#!/bin/bash
#
# Fine-tunes a base Llama model on the Tedim (Zolai) dataset.
# Requires a GPU with sufficient VRAM (e.g., 16GB+ for 4-bit quantization).
#
# Usage:
#   bash workers/tools/run_tedim_finetune.sh
#
# The script will:
# 1. Install Unsloth for fast LoRA fine-tuning.
# 2. Run a Python script to perform the fine-tuning.
# 3. Save the merged, quantized model as a GGUF file.
# 4. Instruct you to update your .env and create the new Ollama model.

set -e

# Safety: Do not run if server load is high.
MAX_LOAD=${LLM_TRAIN_MAX_LOAD:-2.0}
CURRENT_LOAD=$(cut -d' ' -f1 /proc/loadavg)
if (( $(echo "$CURRENT_LOAD > $MAX_LOAD" | bc -l) )); then
    echo "Server load is too high ($CURRENT_LOAD > $MAX_LOAD). Aborting fine-tuning."
    echo "Set LLM_TRAIN_MAX_LOAD to override."
    exit 1
fi

cd "$(dirname "$0")/.." # move to workers/

VENV_PATH=".venv"
if [ ! -d "$VENV_PATH" ]; then
    echo "Virtual environment not found at $VENV_PATH. Please run setup first."
    exit 1
fi

source "$VENV_PATH/bin/activate"

# 1. Create the fine-tuning Python script
# We write it here to keep this self-contained.
FINETUNE_SCRIPT_PATH="tools/_finetune_tedim.py"
cat > "$FINETUNE_SCRIPT_PATH" << 'EOF'
import torch
from unsloth import FastLanguageModel
from datasets import load_dataset
from trl import SFTTrainer
from transformers import TrainingArguments

# --- Configuration ---
MAX_SEQ_LENGTH = 2048
BASE_MODEL = "unsloth/llama-3.2-1b-instruct-bnb-4bit"
DATASET_TRAIN_PATH = "data/tedim_finetune.jsonl"
DATASET_VAL_PATH = "data/tedim_finetune_val.jsonl"
GGUF_OUTPUT_PATH = "data/tedim-zolai-finetuned-q4.gguf"

# --- Load Model ---
model, tokenizer = FastLanguageModel.from_pretrained(
    model_name=BASE_MODEL,
    max_seq_length=MAX_SEQ_LENGTH,
    dtype=None,
    load_in_4bit=True,
)

# --- PEFT Configuration ---
model = FastLanguageModel.get_peft_model(
    model,
    r=16,
    target_modules=["q_proj", "k_proj", "v_proj", "o_proj", "gate_proj", "up_proj", "down_proj"],
    lora_alpha=16,
    lora_dropout=0,
    bias="none",
    use_gradient_checkpointing=True,
    random_state=42,
    use_rslora=False,
    loftq_config=None,
)

# --- Load Dataset ---
dataset = load_dataset(
    "json",
    data_files={"train": DATASET_TRAIN_PATH, "validation": DATASET_VAL_PATH},
)

# --- Train Model ---
trainer = SFTTrainer(
    model=model,
    tokenizer=tokenizer,
    train_dataset=dataset["train"],
    eval_dataset=dataset["validation"],
    dataset_text_field="messages",
    max_seq_length=MAX_SEQ_LENGTH,
    dataset_num_proc=2,
    packing=False,
    args=TrainingArguments(
        per_device_train_batch_size=2,
        gradient_accumulation_steps=4,
        warmup_steps=5,
        max_steps=80,
        learning_rate=2e-4,
        fp16=not torch.cuda.is_bf16_supported(),
        bf16=torch.cuda.is_bf16_supported(),
        logging_steps=1,
        evaluation_strategy="steps",
        eval_steps=10,
        optim="adamw_8bit",
        weight_decay=0.01,
        lr_scheduler_type="linear",
        seed=42,
        output_dir="outputs",
    ),
)
trainer.train()

# --- Save GGUF ---
print(f"Saving GGUF model to {GGUF_OUTPUT_PATH}")
model.save_pretrained_gguf(GGUF_OUTPUT_PATH, tokenizer, quantization_method="q4_k_m")
print("GGUF model saved.")
EOF

# 2. Run the fine-tuning
echo "Starting the fine-tuning process..."
python "$FINETUNE_SCRIPT_PATH"

# 3. Cleanup and instructions
rm "$FINETUNE_SCRIPT_PATH"
echo ""
echo "✅ Fine-tuning complete!"
echo "Your new model is saved at: workers/$GGUF_OUTPUT_PATH"
echo ""
echo "Next steps:"
echo "1. Create a new Modelfile (e.g., TedimFinetunedModelfile):"
echo "   FROM ./data/tedim-zolai-finetuned-q4.gguf"
echo ""
echo "2. Create the Ollama model:"
echo "   ollama create tedim-zolai-finetuned -f TedimFinetunedModelfile"
echo ""
echo "3. Update your workers/.env:"
echo "   OLLAMA_MODEL_TD=tedim-zolai-finetuned"
echo ""
echo "4. Restart the Tedim API service:"
echo "   sudo systemctl restart aivc-tedim-api"