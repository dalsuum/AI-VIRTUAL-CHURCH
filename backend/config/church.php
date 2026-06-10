<?php

return [

    /*
     * Where worshippers reach the app in the browser (the Vite dev server / built
     * SPA). The backend (APP_URL) is a different origin, so links in outbound mail —
     * e.g. the "your scheduled service is ready" reminder — must point here.
     */
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

];
