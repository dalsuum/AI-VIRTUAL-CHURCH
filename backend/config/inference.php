<?php

/**
 * Inference Provider Layer configuration.
 *
 * `providers` are statically-known backends (local Ollama endpoints + Claude). Anything
 * credentialed and admin-managed lives in the ai_provider_profiles table instead and is
 * resolved by name at runtime — both sources feed the same ModelRegistry.
 *
 * `routing` maps a request language to an ordered fallback CHAIN of provider names. The
 * gateway tries each in order. Local models are preferred for their native languages,
 * with Claude as the always-available safety net.
 */

return [
    'metrics_channel' => env('INFERENCE_METRICS_CHANNEL', 'stack'),

    'providers' => [
        'ollama_tedim' => [
            'driver'   => 'ollama',
            'base_url' => env('TEDIM_LLM_URL', 'http://127.0.0.1:8001'),
            'model'    => env('TEDIM_LLM_MODEL', 'tedim-zolai'),
            'timeout'  => 600,
        ],
        'ollama_burmese' => [
            'driver'   => 'ollama',
            'base_url' => env('BURMESE_LLM_URL', 'http://127.0.0.1:8002'),
            'model'    => env('BURMESE_LLM_MODEL', 'burmese-myanmar'),
            'timeout'  => 600,
        ],
        'claude' => [
            'driver'  => 'claude',
            'key'     => env('ANTHROPIC_API_KEY'),
            'model'   => env('CLAUDE_MODEL', 'claude-sonnet-4-6'),
            'timeout' => 120,
        ],
    ],

    /*
     * language → ordered provider fallback chain. Names resolve against the static providers
     * above OR an enabled ai_provider_profiles row of the same name. English (and the hosted
     * fallback for local models) routes to the configured OpenRouter profile by default.
     */
    'routing' => [
        'td' => ['ollama_tedim', env('INFERENCE_HOSTED_PROVIDER', 'OpenRouter (default)')],
        'my' => ['ollama_burmese', env('INFERENCE_HOSTED_PROVIDER', 'OpenRouter (default)')],
        'en' => [env('INFERENCE_HOSTED_PROVIDER', 'OpenRouter (default)')],
    ],

    'default_chain' => [env('INFERENCE_HOSTED_PROVIDER', 'OpenRouter (default)')],

    'circuit' => [
        'failure_threshold' => (int) env('INFERENCE_CB_THRESHOLD', 5),
        'cooldown_seconds'  => (int) env('INFERENCE_CB_COOLDOWN', 30),
    ],

    'retry' => [
        'max'        => (int) env('INFERENCE_RETRY_MAX', 2),
        'backoff_ms' => (int) env('INFERENCE_RETRY_BACKOFF_MS', 250),
    ],

    /*
     * Estimated-cost pricing for the admin AI-usage monitor (GET /admin/ai-usage).
     * `models`  — list price in USD per 1M tokens, [in => input, out => output].
     * `modules` — maps an ai_usage_ledger.module to the model it runs on.
     * Estimates only: authoritative bills live on each provider's dashboard, and
     * media APIs (Suno/RunPod/D-ID) are not token-metered here. Verify and extend
     * `models` against each provider's pricing page when you add a module.
     */
    'pricing' => [
        'models' => [
            'anthropic/claude-sonnet-4-6' => ['in' => 3.0, 'out' => 15.0],
            'openai/gpt-oss-120b:free'    => ['in' => 0.0, 'out' => 0.0],
        ],
        'modules' => [
            'bible_study' => env('BIBLE_STUDY_LLM_MODEL', 'anthropic/claude-sonnet-4-6'),
        ],
    ],
];
