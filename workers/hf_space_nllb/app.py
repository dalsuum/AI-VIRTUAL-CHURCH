"""
NLLB-200 translation Space (ZeroGPU) — English → Burmese for AI Virtual Church.

Runs facebook/nllb-200-distilled-600M on Hugging Face ZeroGPU (free under PRO).
The worker box calls this Space's `/translate` API so the 2.3 GB model stays
off the small CPU server. The model loads on CPU at startup and is moved to the
GPU inside the @spaces.GPU function only while a request runs.
"""
import gradio as gr
import spaces
import torch
from transformers import AutoModelForSeq2SeqLM, AutoTokenizer

MODEL_ID = "facebook/nllb-200-distilled-600M"

tokenizer = AutoTokenizer.from_pretrained(MODEL_ID)
model = AutoModelForSeq2SeqLM.from_pretrained(MODEL_ID)
model.eval()


@spaces.GPU
def translate(text: str, src_lang: str = "eng_Latn", tgt_lang: str = "mya_Mymr") -> str:
    text = (text or "").strip()
    if not text:
        return ""
    model.to("cuda")
    tokenizer.src_lang = src_lang
    bos = tokenizer.convert_tokens_to_ids(tgt_lang)
    enc = tokenizer(text, return_tensors="pt", truncation=True, max_length=512).to("cuda")
    with torch.inference_mode():
        out = model.generate(
            **enc,
            forced_bos_token_id=bos,
            max_new_tokens=512,
            num_beams=4,
            no_repeat_ngram_size=3,
            repetition_penalty=1.3,
        )
    return tokenizer.batch_decode(out, skip_special_tokens=True)[0].strip()


demo = gr.Interface(
    fn=translate,
    inputs=[
        gr.Text(label="text"),
        gr.Text(value="eng_Latn", label="src_lang"),
        gr.Text(value="mya_Mymr", label="tgt_lang"),
    ],
    outputs=gr.Text(label="translation"),
    api_name="translate",
    title="NLLB-200 Translation",
    description="English → Burmese (mya_Mymr) / other NLLB languages.",
)

if __name__ == "__main__":
    demo.launch()
