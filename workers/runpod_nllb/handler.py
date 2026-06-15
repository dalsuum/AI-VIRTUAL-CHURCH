"""RunPod serverless handler for facebook/nllb-200-distilled-600M.

vLLM CANNOT serve NLLB (it is an M2M100ForConditionalGeneration seq2seq model),
so this endpoint runs a plain transformers handler instead. The model loads once
at cold start and is reused across invocations.

Input  (RunPod wraps it as {"input": {...}}):
    {"text": "English source", "src_lang": "eng_Latn", "tgt_lang": "mya_Mymr"}
    - text:     required, the source string to translate
    - src_lang: optional, defaults to "eng_Latn"
    - tgt_lang: optional, defaults to "mya_Mymr" (Burmese). Use "tdt_Latn" for Tedim.

Output:
    {"translation": "...", "src_lang": "...", "tgt_lang": "..."}
"""
import os

import runpod
import torch
from transformers import AutoModelForSeq2SeqLM, AutoTokenizer

MODEL_ID = os.getenv("NLLB_MODEL_ID", "facebook/nllb-200-distilled-600M")
DEFAULT_SRC = "eng_Latn"
DEFAULT_TGT = "mya_Mymr"
MAX_NEW_TOKENS = int(os.getenv("NLLB_MAX_NEW_TOKENS", "512"))

_device = "cuda" if torch.cuda.is_available() else "cpu"
_dtype = torch.float16 if _device == "cuda" else torch.float32

print(f"[nllb] loading {MODEL_ID} on {_device} ({_dtype})...", flush=True)
_tokenizer = AutoTokenizer.from_pretrained(MODEL_ID)
_model = AutoModelForSeq2SeqLM.from_pretrained(MODEL_ID, torch_dtype=_dtype).to(_device)
_model.eval()
print("[nllb] model ready", flush=True)


def handler(job):
    inp = job.get("input") or {}
    text = (inp.get("text") or "").strip()
    if not text:
        return {"error": "missing 'text'"}

    src_lang = inp.get("src_lang") or DEFAULT_SRC
    tgt_lang = inp.get("tgt_lang") or DEFAULT_TGT
    max_new = int(inp.get("max_new_tokens") or MAX_NEW_TOKENS)

    bos = _tokenizer.convert_tokens_to_ids(tgt_lang)
    if bos is None or bos == _tokenizer.unk_token_id:
        return {"error": f"unknown tgt_lang {tgt_lang!r}"}

    _tokenizer.src_lang = src_lang
    enc = _tokenizer(text, return_tensors="pt", truncation=True, max_length=512).to(_device)
    with torch.inference_mode():
        out = _model.generate(
            **enc,
            forced_bos_token_id=bos,
            max_new_tokens=max_new,
            num_beams=4,
        )
    translation = _tokenizer.batch_decode(out, skip_special_tokens=True)[0].strip()
    return {"translation": translation, "src_lang": src_lang, "tgt_lang": tgt_lang}


runpod.serverless.start({"handler": handler})
