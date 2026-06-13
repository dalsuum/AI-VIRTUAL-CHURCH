<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://aivirtual.church',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Required when frontend sends credentials: 'include' (session-cookie auth).
    // Cannot be true with a wildcard allowed_origins.
    'supports_credentials' => true,

];