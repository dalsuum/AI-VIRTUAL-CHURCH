# API Services & Billing — Admin Reference

This document lists every external API/service the AI Church platform depends on,
which ones are **paid / metered** (and therefore can run out of credit or get
suspended), and the **monthly admin checklist** for keeping balances topped up.

> Keep API keys in `.env` only. Never commit them. See `.env.example` for the
> full list of variable names.

Last reviewed: 2026-06-21

---

## 1. Paid / metered services — REQUIRES MONTHLY CHECK

These cost money per use and **must be monitored**. If a balance hits zero the
corresponding feature stops working (often silently, with a fallback or an error).

| Service | Purpose | Env vars | Billing model | Dashboard to check |
|---|---|---|---|---|
| **OpenRouter** | LLM text generation (services, Bible Study discussion). Default model `anthropic/claude-sonnet-4-6`. | `OPENROUTER_API_KEY`, `OPENROUTER_BASE_URL`, `BIBLE_STUDY_LLM_MODEL` | Prepaid credit, pay-per-token | https://openrouter.ai/credits |
| **Suno API** | AI song/hymn audio generation | `SUNO_API_KEY`, `SUNO_API_URL` | Prepaid credit / subscription (per song) | Suno provider account (see `SUNO_POOL_CRUD_MANUAL.md`) |
| **RunPod** | GPU inference: NLLB translation, avatar (wav2lip), optional remote LLM | `RUNPOD_API_KEY`, `RUNPOD_BASE_URL`, `RUNPOD_AVATAR_BASE_URL`, `RUNPOD_LLM_MODEL` | Prepaid balance, billed per GPU-second; **serverless endpoints pause when balance is low** | https://www.runpod.io/console/billing |
| **Stripe** | Offering / donation payments (incoming money — not a cost, but keys must stay valid) | `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `VITE_STRIPE_KEY` | Per-transaction fee deducted from payouts | https://dashboard.stripe.com |
| **S3 / object storage** | Asset storage (audio, video, avatars) | `S3_ENDPOINT`, `S3_ACCESS_KEY`, `S3_SECRET_KEY`, `S3_BUCKET`, `S3_REGION` | Monthly storage + egress invoice | Storage provider console (region `me-dubai-1`) |

---

## 2. Free-tier / quota-limited services — CHECK QUOTAS

Free but rate/quota limited. They won't bill you, but can **stop returning data**
when the daily/monthly quota is exhausted.

| Service | Purpose | Env vars | Limit | Dashboard |
|---|---|---|---|---|
| **YouTube Data API** | Hymn video lookup/embeds | `YOUTUBE_API_KEY` | Daily quota units (default 10,000/day) | https://console.cloud.google.com/apis |

> **ESV Bible API — NOT USED (application declined 2026-06-11).** Crossway's
> Licensing team declined our application because they do not approve pairing ESV
> text with AI-generated text. All ESV code/config has been removed. Scripture
> text comes from the local public-domain corpus (BSB fallback) only — do not
> re-add an ESV key.

---

## 3. Self-hosted / no recurring API cost

These run on our own server(s) — no external billing, only electricity/compute.
No monthly recharge needed; monitor that the services are **running** instead
(see `SERVICE_RESTART_RUNBOOK.md`).

| Service | Purpose | Env vars |
|---|---|---|
| Ollama — Tedim/Zolai LLM | Local Tedim text | `TEDIM_LLM_URL`, `OLLAMA_MODEL_TD` |
| Ollama — Burmese LLM | Local Burmese text | `BURMESE_LLM_URL`, `OLLAMA_MODEL_MY` |
| Chin/Zo routers (Falam/Hakha/Mizo/Paite/Lai) | Local Chin-language LLM/TTS | port `:8001` (`chin_router.py`) |
| MMS-TTS / MMS-ASR | Myanmar/Tedim narration + transcript checks | `MMS_SPEECH_URL`, `MMS_TTS_URL`, `MMS_ASR_MODEL` |
| edge-tts | English/legacy fallback narration | `EDGE_TTS_VOICE_MY`, `EDGE_TTS_VOICE_TD` |
| Redis | Worker job queue | `REDIS_URL`, `REDIS_HOST` |
| MySQL | Application database | `DB_*` |

---

## 4. Monthly Admin Checklist

Run on the **1st of each month** (and any time a feature stops generating output):

- [ ] **OpenRouter** — open https://openrouter.ai/credits, confirm credit balance
      is above one month of usage; top up if low. (Affects all AI text + Bible Study.)
- [ ] **RunPod** — open https://www.runpod.io/console/billing, confirm balance is
      positive; recharge. (Low balance pauses NLLB translation + avatar/wav2lip.)
- [ ] **Suno** — log into the Suno provider account, confirm song-generation
      credits/subscription is active; renew if expired. (Affects hymn audio.)
- [ ] **Stripe** — confirm account is active and not in review; check that
      payouts are succeeding and webhook signing secret is unchanged.
- [ ] **S3 storage** — review the monthly storage invoice and confirm payment
      method is valid; watch bucket size growth.
- [ ] **YouTube Data API** — check quota usage in Google Cloud Console; request
      a quota increase if approaching the daily cap.
- [ ] **Key rotation** — confirm no API key is near an expiry/rotation deadline.

### What breaks if a balance runs out

| If this runs dry… | …this stops working |
|---|---|
| OpenRouter credit | Service text + AI Bible Study discussion (may fall back to local models / error) |
| RunPod balance | NLLB translation, avatar lip-sync video |
| Suno credits | New AI hymn/song audio |
| Stripe inactive | Offerings/donations can't be collected |
| S3 unpaid | Asset upload/playback fails |
| YouTube quota | Hymn video embeds stop resolving |

---

_Maintainer note: when adding a new external API, add a row here AND a line to the
monthly checklist, and add the env var name to `.env.example`._
