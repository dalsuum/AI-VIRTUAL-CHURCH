# Training a custom Tedim / Burmese voice (MMS-TTS fine-tune)

Use this only if the stock MMS voices aren't good enough or you want a
specific voice (e.g., your pastor's) for aivirtual.church. Otherwise the
stock checkpoints in `mms_tts_service.py` are usable today. In this app,
Admin must also enable the language narration toggle (`narration_my` or
`narration_td`) and set `narration_mode=edge_tts`; the worker then routes
Myanmar/Tedim text to MMS-TTS through `MMS_TTS_URL`.

## What TTS training actually needs

(text, audio) pairs — NOT an LLM. Target: **1–5 hours** of ONE speaker,
clean room, consistent mic. Fine-tuning an existing MMS checkpoint needs
far less data than training from scratch (which needs 10h+).

Your two LLMs participate in three places only:
1. **Recording-script generation** — select phonetically diverse sentences
   from tedim_text.jsonl / your Burmese corpus (script below).
2. **Text normalization** — at inference, numbers/refs/loanwords must be
   spelled out before hitting VITS.
3. **QC loop** — transcribe synthesized audio with MMS-ASR
   (facebook/mms-1b-all supports ctd and mya) and diff against input.

## Step 1 — Recording scripts from your corpus

```python
# pick ~1200 sentences, 3-15 words, maximizing character-bigram coverage
import json, collections

def pick(corpus_path, n=1200):
    rows = [json.loads(l)["text"] for l in open(corpus_path, encoding="utf-8")]
    sents = [s.strip() for r in rows for s in r.split(".")
             if 3 <= len(s.split()) <= 15]
    seen, picked = collections.Counter(), []
    for s in sorted(set(sents), key=len):
        bigrams = {s[i:i+2] for i in range(len(s) - 1)}
        if sum(seen[b] == 0 for b in bigrams) >= 2 or len(picked) < 300:
            picked.append(s); seen.update(bigrams)
        if len(picked) >= n:
            break
    return picked

for i, s in enumerate(pick("tedim_text.jsonl"), 1):
    print(f"{i:04d}|{s}")
```

Record with Audacity: 48 kHz mono WAV, one sentence per file
(`0001.wav` ... `1200.wav`), quiet room, pop filter, consistent distance.
~1200 short sentences ≈ 2–2.5 hours of audio ≈ 2 evenings of recording.

## Step 2 — Dataset assembly

```
dataset/
  metadata.csv        # file_name,text
  wavs/0001.wav ...
```

```bash
# resample to 16 kHz (MMS-VITS native rate), trim silence
for f in raw/*.wav; do
  ffmpeg -i "$f" -ar 16000 -ac 1 -af \
    "silenceremove=start_periods=1:start_threshold=-45dB,areverse,silenceremove=start_periods=1:start_threshold=-45dB,areverse" \
    "dataset/wavs/$(basename "$f")"
done
```

If you instead use LONG recordings (e.g., existing sermon audio of one
speaker), segment + align first with MMS forced alignment:
https://huggingface.co/blog/mms-adapters — or ASR-assist with
facebook/mms-1b-all (ctd/mya adapters) to draft transcripts, then
hand-correct. NOTE: do NOT scrape bible.is / FCBH audio — it is
copyrighted; record your own readers or get written permission.

## Step 3 — Fine-tune (Colab T4, ~2-4 h)

Uses the HF-maintained VITS finetuning repo (handles discriminator
checkpoints that the base MMS release omits):

```bash
git clone https://github.com/ylacombe/finetune-hf-vits && cd finetune-hf-vits
pip install -r requirements.txt

# pull base checkpoint WITH discriminator for your language
python convert_original_discriminator_checkpoint.py \
  --language_code ctd --pytorch_dump_folder_path ./mms-ctd-train
# (use --language_code mya for the Burmese voice)
```

`finetune_ctd.json`:
```json
{
  "model_name_or_path": "./mms-ctd-train",
  "dataset_name": "./dataset",
  "audio_column_name": "audio",
  "text_column_name": "text",
  "train_split_name": "train",
  "output_dir": "./tedim-voice-pastor",
  "per_device_train_batch_size": 8,
  "gradient_accumulation_steps": 2,
  "learning_rate": 2e-5,
  "num_train_epochs": 200,
  "fp16": true,
  "do_train": true, "do_eval": true,
  "weight_disc": 3, "weight_fmaps": 1, "weight_gen": 1,
  "weight_kl": 1.5, "weight_duration": 1, "weight_mel": 35
}
```

```bash
accelerate launch run_vits_finetuning.py finetune_ctd.json
```

Checkpoint every ~50 epochs and listen — VITS overfits small datasets;
best voice is often NOT the last epoch.

## Step 4 — Deploy

Push the folder to your private HF repo (or scp), then in
`mms_tts_service.py` change:

```python
MODELS = {
    "tedim": "dalsuum/tedim-voice-pastor",   # your fine-tune
    "burmese": "facebook/mms-tts-mya",
}
```

Same VITS size, same ARM CPU speed. Nothing else in the pipeline changes.

## Step 5 — QC loop (where the LLM earns its keep)

```python
# round-trip: TTS -> MMS-ASR -> compare
from transformers import pipeline
asr = pipeline("automatic-speech-recognition",
               model="facebook/mms-1b-all",
               model_kwargs={"target_lang": "ctd", "ignore_mismatched_sizes": True})
hyp = asr("seg_0001.wav")["text"]
# then ask church-multilingual: "Do these two Tedim sentences mean the
# same? Answer SAME or DIFFERENT: ..." -> auto-flag bad segments for
# human review instead of reviewing every clip.
```

## Effort summary

| Option                      | Effort       | Result                    |
|-----------------------------|--------------|---------------------------|
| Stock mms-tts-ctd / -mya    | 1 hour       | Generic but native voices |
| Fine-tuned custom voice     | 2-3 weekends | Your chosen voice         |
| From-scratch (Piper/Coqui)  | months       | Not worth it vs MMS base  |
