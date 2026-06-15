# NLLB-200 RunPod serverless worker

Custom serverless handler for `facebook/nllb-200-distilled-600M`.

**Why custom (not vLLM):** NLLB is an `M2M100ForConditionalGeneration` seq2seq
model. vLLM only serves decoder-only causal LMs, so the vLLM template fails at
startup with `Model architectures ['M2M100ForConditionalGeneration'] are not
supported` and the worker never consumes the job queue. This image runs a plain
`transformers` handler instead.

## Build & push

```bash
cd workers/runpod_nllb
docker build -t <dockerhub-user>/nllb-runpod:latest .
docker push <dockerhub-user>/nllb-runpod:latest
```

## Configure the RunPod endpoint

In the RunPod console, edit endpoint `jin86ntuqqpnsc` (or make a new one):
- **Container image:** `<dockerhub-user>/nllb-runpod:latest`
- **Container disk:** ≥ 10 GB (model is baked into the image)
- Remove any vLLM template settings.

## Request / response

```json
// POST https://api.runpod.ai/v2/<id>/runsync   (Bearer RUNPOD_API_KEY)
{"input": {"text": "The Lord is our shepherd.", "src_lang": "eng_Latn", "tgt_lang": "mya_Mymr"}}
```

```json
{"output": {"translation": "...", "src_lang": "eng_Latn", "tgt_lang": "mya_Mymr"}}
```

Language codes: Burmese = `mya_Mymr`, Tedim = `tdt_Latn` (Tedim quality is poor —
the app only uses this endpoint for Burmese; Tedim stays on local Ollama).
