# Custom RunPod serverless worker for NLLB-200 translation.
# vLLM cannot serve NLLB (M2M100ForConditionalGeneration), so we use transformers.
FROM runpod/base:0.6.2-cuda12.1.0

WORKDIR /

COPY requirements.txt /requirements.txt
RUN python3 -m pip install --no-cache-dir -r /requirements.txt

# Bake the model into the image so cold starts don't re-download ~2.4 GB.
ENV NLLB_MODEL_ID=facebook/nllb-200-distilled-600M
RUN python3 -c "from transformers import AutoModelForSeq2SeqLM, AutoTokenizer; \
    AutoTokenizer.from_pretrained('${NLLB_MODEL_ID}'); \
    AutoModelForSeq2SeqLM.from_pretrained('${NLLB_MODEL_ID}')"

COPY handler.py /handler.py

CMD ["python3", "-u", "/handler.py"]
