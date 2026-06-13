#!/usr/bin/env bash
set -Eeuo pipefail

: "${DATASET_DIR:?DATASET_DIR is required}"
: "${DATASET_SCRIPT:?DATASET_SCRIPT is required}"
: "${OUTPUT_DIR:?OUTPUT_DIR is required}"
: "${LANGUAGE_CODE:?LANGUAGE_CODE is required, e.g. ctd or mya}"

ROOT_DIR="${VOICE_TRAIN_ROOT:-/opt/ai-church}"
if [[ -f "$ROOT_DIR/workers/.env" ]]; then
  set -a
  # shellcheck disable=SC1091
  source "$ROOT_DIR/workers/.env"
  set +a
fi

PYTHON="${VOICE_TRAIN_PYTHON:-$ROOT_DIR/workers/.venv/bin/python}"
WORKDIR="${VOICE_TRAIN_WORKDIR:-$ROOT_DIR/workers/.voice-train}"
REPO_URL="${VOICE_TRAIN_REPO_URL:-https://github.com/ylacombe/finetune-hf-vits.git}"
REPO_DIR="$WORKDIR/finetune-hf-vits"
BASE_DIR="$WORKDIR/mms-${LANGUAGE_CODE}-train"

EPOCHS="${VOICE_TRAIN_EPOCHS:-120}"
BATCH_SIZE="${VOICE_TRAIN_BATCH_SIZE:-8}"
LEARNING_RATE="${VOICE_TRAIN_LEARNING_RATE:-2e-5}"
GRAD_ACCUM="${VOICE_TRAIN_GRADIENT_ACCUMULATION:-2}"

mkdir -p "$WORKDIR" "$OUTPUT_DIR"

if [[ ! -x "$PYTHON" ]]; then
  echo "Python not found or not executable: $PYTHON" >&2
  exit 2
fi

if [[ ! -d "$DATASET_DIR/wavs" || ! -f "$DATASET_DIR/metadata.csv" ]]; then
  echo "Dataset must contain wavs/ and metadata.csv: $DATASET_DIR" >&2
  exit 2
fi
if [[ ! -f "$DATASET_SCRIPT" ]]; then
  echo "Dataset loader script not found: $DATASET_SCRIPT" >&2
  exit 2
fi

if [[ ! -d "$REPO_DIR/.git" ]]; then
  git clone --depth 1 "$REPO_URL" "$REPO_DIR"
else
  git -C "$REPO_DIR" pull --ff-only || true
fi

"$PYTHON" -m pip install -r "$REPO_DIR/requirements.txt"

if [[ ! -d "$BASE_DIR" ]]; then
  "$PYTHON" "$REPO_DIR/convert_original_discriminator_checkpoint.py" \
    --language_code "$LANGUAGE_CODE" \
    --pytorch_dump_folder_path "$BASE_DIR"
fi

CONFIG="$WORKDIR/finetune_${LANGUAGE_CODE}_$(date +%Y%m%d_%H%M%S).json"
cat > "$CONFIG" <<JSON
{
  "model_name_or_path": "$BASE_DIR",
  "dataset_name": "$DATASET_SCRIPT",
  "audio_column_name": "audio",
  "text_column_name": "text",
  "train_split_name": "train",
  "output_dir": "$OUTPUT_DIR",
  "overwrite_output_dir": true,
  "per_device_train_batch_size": $BATCH_SIZE,
  "gradient_accumulation_steps": $GRAD_ACCUM,
  "learning_rate": $LEARNING_RATE,
  "num_train_epochs": $EPOCHS,
  "fp16": false,
  "do_train": true,
  "do_eval": false,
  "weight_disc": 3,
  "weight_fmaps": 1,
  "weight_gen": 1,
  "weight_kl": 1.5,
  "weight_duration": 1,
  "weight_mel": 35
}
JSON

ACCELERATE="${VOICE_TRAIN_ACCELERATE:-$(dirname "$PYTHON")/accelerate}"
if [[ -x "$ACCELERATE" ]]; then
  "$ACCELERATE" launch "$REPO_DIR/run_vits_finetuning.py" "$CONFIG"
else
  "$PYTHON" -m accelerate.commands.launch "$REPO_DIR/run_vits_finetuning.py" "$CONFIG"
fi

echo "Fine-tuned model written to $OUTPUT_DIR"
