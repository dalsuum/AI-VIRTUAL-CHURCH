<?php

namespace App\Http\Controllers;

use App\Services\FeatureService;
use App\Services\StripeEventGuard;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Self-serve subscription management for registered users. Plan columns are never
 * mutated here directly — everything goes through SubscriptionService, and premium
 * activation only happens via the Stripe-verified webhook (see WebhookController).
 */
class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions) {}

    /** Current plan + entitlements for the account page. */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $f    = FeatureService::for($user);

        return response()->json([
            'plan'              => $user->plan()->value,
            'status'            => $user->subscriptionStatus()->value,
            'expires_at'        => $user->subscription_expires_at,
            'is_premium'        => $user->isPremium(),
            'token_balance'     => (int) $user->token_balance,
            'monthly_allowance' => $f->monthlyAllowance(),
            'max_pastors'       => $f->maxPastors(),
            'shows_ads'         => $f->showsAds(),
            // Lets the account page hide the upgrade CTA when no provider is
            // configured, instead of offering a checkout that can't complete.
            'billing_enabled'   => \App\Http\Controllers\AuthController::billingEnabled(),
        ]);
    }

    /** Begin a Stripe Checkout for premium; returns the hosted-page URL to redirect to. */
    public function checkout(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! AuthController::billingEnabled()) {
            return response()->json(['message' => 'Subscriptions are not available right now.'], 503);
        }
        if ($user->isGuestAccount()) {
            return response()->json(['message' => 'Please register before subscribing.'], 422);
        }
        if ($user->isPremium()) {
            return response()->json(['message' => 'You are already on the premium plan.'], 422);
        }

        $base = rtrim(config('app.frontend_url', config('app.url')), '/');
        $url  = $this->subscriptions->startCheckout(
            $user,
            $base . '/account?subscription=success',
            $base . '/account?subscription=cancelled',
        );

        return response()->json(['checkout_url' => $url]);
    }

    /** Cancel at period end. Access is retained until expiry (status → cancelled). */
    public function cancel(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isPremium()) {
            return response()->json(['message' => 'No active premium subscription to cancel.'], 422);
        }

        $ok = $this->subscriptions->requestCancel($user);

        return response()->json([
            'message' => $ok
                ? 'Your subscription will end at the close of the current billing period.'
                : 'We could not cancel the subscription. Please contact support.',
        ], $ok ? 200 : 422);
    }

    /**
     * Stripe-signature-verified subscription webhook. This is the ONLY place premium is
     * activated/downgraded — we never trust the client. Mirrors OfferingController's
     * verification. Acknowledges all events so Stripe stops retrying ones we ignore.
     */
    public function webhook(Request $request, StripeEventGuard $guard): JsonResponse
    {
        $secret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                (string) $secret,
            );
        } catch (\UnexpectedValueException | SignatureVerificationException) {
            abort(400, 'Invalid Stripe signature.');
        }

        // Process exactly once: the marker insert + side effects share one transaction.
        $guard->once($event->id, $event->type, function () use ($event) {
            $this->subscriptions->handleStripeEvent($event->type, $event->data->object);
        });

        return response()->json(['received' => true]);
    }
}
