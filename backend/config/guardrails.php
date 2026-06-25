<?php

/**
 * Guardrails layer configuration — ORDER + ENABLEMENT + POLICY, all external to guard code.
 *
 *  *.order            : global priority order (list of guard keys). Guards not listed are
 *                       not run; reorder/remove here without touching code.
 *  *.disabled.<cap>   : guard keys switched off for a capability (session_type) key.
 *  policies.*         : the rules each guard consults via PolicyRepository.
 *
 * Patterns starting/ending with a delimiter (e.g. /…/i) are treated as regex; bare strings
 * are literal phrase/term matches.
 */

return [
    'input' => [
        'order' => ['rate_limit', 'crisis', 'prompt_injection', 'abuse', 'pii'],
        'disabled' => [
            // e.g. 'worship' => ['pii'],
        ],
    ],

    'output' => [
        'order' => [
            'html_sanitizer', 'markdown_sanitizer', 'content_moderation',
            'hallucination', 'citation', 'theology', 'username_sanitizer',
        ],
        'disabled' => [
            'prayer'  => ['citation', 'hallucination'],
            'worship' => ['citation', 'hallucination', 'theology'],
        ],
    ],

    'policies' => [
        'rate' => [
            'max'   => (int) env('CHAT_RATE_MAX', 30),
            'decay' => (int) env('CHAT_RATE_DECAY', 60),
        ],

        'injection' => [
            'patterns' => [
                '/ignore (?:all |the )?(?:previous|prior|above) (?:instructions|messages)/i',
                '/disregard (?:the )?system prompt/i',
                '/you are now (?:dan|a|an) /i',
                '/reveal (?:your )?(?:system )?(?:prompt|instructions)/i',
                '/pretend (?:to be|you are)/i',
                'jailbreak',
            ],
        ],

        'abuse' => [
            // Populate per the church's community standards; kept empty by default so the
            // platform ships without baking in a word list.
            'terms' => [],
        ],

        'pii' => [
            'action'   => env('CHAT_PII_ACTION', 'flag'), // 'flag' | 'block'
            'patterns' => [
                '/\b(?:\d[ -]?){13,16}\b/',                 // card-like number runs
                '/\b[\w.+-]+@[\w-]+\.[\w.-]+\b/',           // email
            ],
        ],

        'moderation' => [
            'terms' => [],
        ],

        'hallucination' => [
            'min_overlap' => (float) env('CHAT_HALLUCINATION_MIN_OVERLAP', 0.12),
            'action'      => env('CHAT_HALLUCINATION_ACTION', 'flag'),
        ],

        'citation' => [
            'action' => env('CHAT_CITATION_ACTION', 'flag'),
        ],

        'theology' => [
            'action'                   => env('CHAT_THEOLOGY_ACTION', 'flag'),
            'forbidden_phrases'        => [],
            'require_citation_markers' => ['the bible says', 'scripture teaches', 'god commands'],
        ],
    ],
];
