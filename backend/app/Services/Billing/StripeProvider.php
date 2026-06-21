<?php

namespace App\Services\Billing;

use App\Models\User;
use Stripe\StripeClient;

/**
 * Stripe implementation of the billing seam. Uses Stripe Checkout (hosted) for the
 * premium subscription so no card data touches our server, mirroring the offering flow.
 * Activation happens later, in the webhook — never here.
 */
class StripeProvider implements BillingProvider
{
    public function __construct(private StripeClient $stripe) {}

    public function createCheckout(User $user, string $successUrl, string $cancelUrl): string
    {
        $price = config('tokens.stripe_premium_price');
        abort_unless($price, 503, 'Premium price is not configured.');

        // Reuse the user's Stripe customer if we have one, so subscriptions don't
        // fragment across duplicate customers.
        $params = [
            'mode'        => 'subscription',
            'line_items'  => [['price' => $price, 'quantity' => 1]],
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
            // Echoed back on every webhook so we attribute the subscription to this
            // user without trusting client input.
            'client_reference_id' => (string) $user->id,
            'metadata'            => ['user_id' => (string) $user->id],
            'subscription_data'   => ['metadata' => ['user_id' => (string) $user->id]],
        ];

        if ($user->stripe_customer_id) {
            $params['customer'] = $user->stripe_customer_id;
        } else {
            $params['customer_email'] = $user->isGuestAccount() ? null : $user->email;
        }

        $session = $this->stripe->checkout->sessions->create(array_filter($params, fn ($v) => $v !== null));

        return $session->url;
    }

    public function cancel(User $user): bool
    {
        if (! $user->stripe_subscription_id) {
            return false;
        }

        $this->stripe->subscriptions->update(
            $user->stripe_subscription_id,
            ['cancel_at_period_end' => true]
        );

        return true;
    }
}
