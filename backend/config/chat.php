<?php

/**
 * Chat Orchestrator layer configuration. The orchestration envelope timeout bounds the
 * whole 13-step pipeline for one turn (provider HTTP timeouts remain the hard stop for a
 * single inference call).
 */
return [
    'turn_timeout' => (int) env('CHAT_TURN_TIMEOUT', 90),

    // Number of prior turns loaded into prompt context.
    'history_window' => (int) env('CHAT_HISTORY_WINDOW', 20),
];
