<?php

return [

    /*
     * Internal worker callback secret. The Python workers send this in the
     * X-Worker-Secret header when POSTing finished assets to /api/internal/asset-ready.
     */
    'worker' => [
        'secret' => env('WORKER_WEBHOOK_SECRET', ''),
    ],

    /*
     * Stripe — the offering segment. We only ever hold the secret key server-side;
     * the publishable key is handed to the browser to mount the Payment Element.
     * The webhook secret verifies that incoming events genuinely came from Stripe.
     */
    'stripe' => [
        'key'            => env('STRIPE_KEY'),
        'secret'         => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'currency'       => env('STRIPE_CURRENCY', 'USD'),
    ],

];
