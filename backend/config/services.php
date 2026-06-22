<?php

return [

    /*
     * Internal worker callback secret. The Python workers send this in the
     * X-Worker-Secret header when POSTing finished assets to /api/internal/asset-ready.
     */
    'worker' => [
        'secret' => env('WORKER_WEBHOOK_SECRET'),
    ],

    /*
     * Stripe — the offering segment. We only ever hold the secret key server-side;
     * the publishable key is handed to the browser to mount the Payment Element.
     * The webhook secret verifies that incoming events genuinely came from Stripe.
     */
    /*
     * Tedim LLM — local FastAPI service wrapping Ollama (workers/api.py).
     * Translates prose to Tedim (Zolai) and looks up exact Bible verses from
     * the vendored Lai Siangtho corpus. Only active for Tedim-language services.
     */
    'tedim_llm' => [
        'url' => env('TEDIM_LLM_URL', 'http://127.0.0.1:8001'),
    ],

    /*
     * Burmese LLM — local FastAPI service wrapping Ollama (workers/api.py).
     * Translates prose to Myanmar Burmese and looks up exact Bible verses from
     * the vendored Judson 1835 corpus. Only active for Myanmar-language services.
     * Output is always Myanmar Unicode (never Zawgyi).
     */
    'burmese_llm' => [
        'url' => env('BURMESE_LLM_URL', 'http://127.0.0.1:8002'),
    ],

    /*
     * MMS speech — local FastAPI service for native Myanmar/Tedim TTS and optional
     * ASR transcript checks from Voice Studio. Defaults to the dedicated speech
     * process; MMS_TTS_URL is kept as a backwards-compatible env name.
     */
    'mms_speech' => [
        'url' => env('MMS_SPEECH_URL', env('MMS_TTS_URL', 'http://127.0.0.1:8003')),
        'asr_timeout' => (int) env('MMS_ASR_TIMEOUT', 300),
    ],

    'stripe' => [
        'key'            => env('STRIPE_KEY'),
        'secret'         => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'currency'       => env('STRIPE_CURRENCY', 'USD'),
    ],

    /*
     * YouTube Data API v3 — used by the Worship Radio admin tool to search for
     * official worship-song uploads and attach embeddable links to catalog
     * tracks. Reuses the same key the workers use; set YOUTUBE_API_KEY in the
     * backend .env too (it currently only lives in workers/.env).
     */
    'youtube' => [
        'key' => env('YOUTUBE_API_KEY'),
    ],

];
