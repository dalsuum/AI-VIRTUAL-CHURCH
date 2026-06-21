<?php

namespace App\Services\Billing;

use App\Models\User;

/**
 * Payment-provider seam. Only StripeProvider exists today, but SubscriptionService
 * depends on this interface so a second provider (Paddle, LemonSqueezy) can be added
 * later without touching the controller or subscription logic.
 */
interface BillingProvider
{
    /** Start a hosted checkout for the premium subscription; return the redirect URL. */
    public function createCheckout(User $user, string $successUrl, string $cancelUrl): string;

    /** Cancel the user's subscription at period end. Returns true on success. */
    public function cancel(User $user): bool;
}
