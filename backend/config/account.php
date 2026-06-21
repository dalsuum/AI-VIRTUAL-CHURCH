<?php

/**
 * Account activation / email-verification settings. All tunable via env so no value
 * is hard-coded against the flow.
 */
return [
    // How long an activation link stays valid before the hourly cleanup job removes
    // the still-pending account.
    'verification_expires_hours' => (int) env('EMAIL_VERIFICATION_EXPIRES_HOURS', 24),

    // Admin-created users: when false (recommended) they are provisioned active and
    // granted the member package immediately; when true they must verify by email like
    // a self-service registrant.
    'admin_requires_verification' => (bool) env('ADMIN_USERS_REQUIRE_VERIFICATION', false),
];
