#!/usr/bin/env python3
"""Quality probe for facebook/nllb-200-distilled-600M on worship prose.

Runs real English service segments through NLLB into Burmese (mya_Mymr) and
Tedim (tdt_Latn) so a native speaker can eyeball quality BEFORE we deploy the
model to RunPod or wire it into llm_engine. Pure CPU; downloads ~2.4 GB once
into the local HF cache.

Usage:
    python3 workers/tools/test_nllb_translate.py
"""
from transformers import AutoTokenizer, AutoModelForSeq2SeqLM

MODEL_ID = "facebook/nllb-200-distilled-600M"

# Tedim in Latin script; Burmese in native Myanmar script.
TARGETS = {"Tedim": "tdt_Latn", "Burmese": "mya_Mymr"}

# Representative segments the live pipeline produces in English.
SAMPLES = [
    "Welcome, dear friends. We gather today in the presence of God, "
    "grateful for His grace and mercy that carries us through every season.",
    "Let us pray. Heavenly Father, we thank You for Your steadfast love. "
    "Guide our hearts, forgive our shortcomings, and fill us with Your peace.",
    "The Lord is our shepherd; we shall not want. Even in the valley of "
    "shadow, His goodness and mercy follow us, and we find rest in Him.",
    "Go now in peace. May the grace of our Lord Jesus Christ, the love of "
    "God, and the fellowship of the Holy Spirit be with you all. Amen.",
]


def main() -> None:
    print(f"Loading {MODEL_ID} (first run downloads ~2.4 GB)...", flush=True)
    tokenizer = AutoTokenizer.from_pretrained(MODEL_ID)
    model = AutoModelForSeq2SeqLM.from_pretrained(MODEL_ID)
    model.eval()

    for lang, code in TARGETS.items():
        print("\n" + "=" * 70)
        print(f"  {lang}  ({code})")
        print("=" * 70)
        bos = tokenizer.convert_tokens_to_ids(code)
        for src in SAMPLES:
            tokenizer.src_lang = "eng_Latn"
            inputs = tokenizer(src, return_tensors="pt", truncation=True, max_length=512)
            out = model.generate(
                **inputs,
                forced_bos_token_id=bos,
                max_new_tokens=512,
                num_beams=4,
            )
            translated = tokenizer.batch_decode(out, skip_special_tokens=True)[0]
            print(f"\nEN: {src}")
            print(f"{lang[:2].upper()}: {translated}")


if __name__ == "__main__":
    main()
