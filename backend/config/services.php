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

    'stripe' => [
        'key'            => env('STRIPE_KEY'),
        'secret'         => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'currency'       => env('STRIPE_CURRENCY', 'USD'),
    ],

];
