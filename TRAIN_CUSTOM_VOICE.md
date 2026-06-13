# Training a custom Tedim / Burmese voice (MMS-TTS fine-tune)

Use this only if the stock MMS voices aren't good enough or you want a
specific voice (e.g., your pastor's) for aivirtual.church. Otherwise the
stock checkpoints in `mms_tts_service.py` are usable today. In this app,
Admin must also enable the language narration toggle (`narration_my` or
`narration_td`) and set the language's narration mode to `mms_tts`; the worker
then routes Myanmar/Tedim text to MMS-TTS through `MMS_TTS_URL`.

Voice Studio records training data and refreshes the dataset snapshot after every
accepted clip. Laravel's scheduler then starts the MMS/VITS fine-tune
automatically between 2AM and 6AM when the server load is below the configured
threshold. The optional STT check calls the local MMS-ASR endpoint to spot-check
a clip before you accept it.

## What TTS training actually needs

(text, audio) pairs — NOT an LLM. Target: **1–5 hours** of ONE speaker,
clean room, consistent mic. Fine-tuning an existing MMS checkpoint needs
far less data than training from scratch (which needs 10h+).

Your two LLMs participate in three places only:
1. **Recording-script generation** — select phonetically diverse sentences
   from tedim_text.jsonl / your Burmese corpus (script below).
2. **Text normalization** — at inference, numbers/refs/loanwords must be
   spelled out before hitting VITS.
3. **QC loop** — transcribe recorded or synthesized audio with MMS-ASR
   (`facebook/mms-1b-all`, target languages `ctd` and `mya`) and diff against input.

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

## Step 2 — Dataset assembly happens automatically

Each accepted browser recording is converted to 16 kHz mono WAV and copied into:

```
backend/storage/app/voice-studio/{user_id}/{lang}/dataset/
  metadata.csv
  wavs/0001.wav ...
```

Manual export is still available for inspection, but it is not part of the normal
training path.

If you instead use LONG recordings (e.g., existing sermon audio of one
speaker), segment + align first with MMS forced alignment:
https://huggingface.co/blog/mms-adapters — or ASR-assist with
facebook/mms-1b-all (ctd/mya adapters) to draft transcripts, then
hand-correct. NOTE: do NOT scrape bible.is / FCBH audio — it is
copyrighted; record your own readers or get written permission.

## Step 3 — Fine-tune automatically during low-load hours

The scheduler runs `voice-studio:train-due` every 30 minutes between 2AM and 6AM.
The command starts at most one training run if:

- `VOICE_TRAIN_ENABLED=true`
- one language dataset has at least `VOICE_TRAIN_MIN_CLIPS`
- at least `VOICE_TRAIN_MIN_NEW_CLIPS` were added since the last successful model
- the 1-minute load average is at or below `VOICE_TRAIN_MAX_LOAD`

The default command is:

```bash
/opt/ai-church/workers/tools/run_mms_vits_finetune.sh
```

It clones/updates the HF-maintained VITS finetuning repo, converts the MMS
checkpoint with discriminator weights for `ctd` or `mya`, writes the fine-tune
config, and launches training with `accelerate`.

## Step 4 — Activation

When a scheduled fine-tune succeeds, Laravel writes the finished model path to:

```
backend/storage/app/voice-studio/active_models.json
```

The MMS-TTS service reads that file when `MMS_TTS_AUTO_ACTIVE=1`, prefers the
fine-tuned local model for that language, and falls back to the stock checkpoint
if no trained model exists. The trainer also calls `/tts/reload` so the next
narration request can use the new model without waiting for a manual restart.

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
