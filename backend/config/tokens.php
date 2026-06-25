<?php

/**
 * Plan & token-economy defaults. These are *fallbacks* — admins override them live via
 * the `plan_overrides` Setting key (App\Services\PlanService), so limits change without
 * a deploy. PlanService is the ONLY place these are read; controllers ask it, never the
 * config directly.
 *
 * Per-plan keys:
 *   monthly_allowance — tokens granted on the monthly refill (guests get none — they
 *                       receive a single free use per service via GuestUsageService).
 *   ads               — whether ad components are shown to this plan.
 *   features          — boolean capability flags surfaced by FeatureService.
 *
 * `costs` is the token price per AI action, keyed by service; `default` applies when a
 * service has no explicit entry. Max-pastors is intentionally NOT here: it stays in the
 * admin-editable App\Services\StudyTiers (which PlanService delegates to).
 */
return [
    'plans' => [
        'guest' => [
            'monthly_allowance' => 0,
            'ads'               => true,
            'features'          => ['voice' => false, 'video' => false, 'export' => false, 'priority' => false],
        ],
        'member' => [
            'monthly_allowance' => (int) env('MEMBER_MONTHLY_TOKENS', env('TOKENS_MEMBER_MONTHLY', 100)),
            'ads'               => false,
            'features'          => ['voice' => true, 'video' => false, 'export' => false, 'priority' => false],
        ],
        'premium' => [
            'monthly_allowance' => (int) env('TOKENS_PREMIUM_MONTHLY', 1000),
            'ads'               => false,
            'features'          => ['voice' => true, 'video' => true, 'export' => true, 'priority' => true],
        ],
    ],

    'costs' => [
        'default' => 1,
        'study'   => 1,   // one AI Bible Study session
        'service' => 1,   // one generated worship service
        'pastor'  => 1,   // one AI Pastor Chat session
    ],

    // How long a token reservation may stay pending before reservations:cleanup
    // rolls it back (a worker that died mid-request must not strand tokens).
    'reservation_ttl_minutes' => (int) env('TOKENS_RESERVATION_TTL', 30),

    // How long guest-usage rows are retained before guests:cleanup prunes them.
    'guest_tracking_retention_days' => (int) env('GUEST_TRACKING_RETENTION_DAYS', 90),

    // Stripe price id for the premium subscription (test/live set via env).
    'stripe_premium_price' => env('STRIPE_PREMIUM_PRICE_ID'),
];
