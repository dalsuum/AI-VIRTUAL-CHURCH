<?php

namespace App\Services;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\SubscriptionHistory;
use App\Models\User;
use App\Services\Billing\BillingProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Owns the subscription lifecycle: starting checkout, applying provider events, and
 * recording every plan transition to subscription_history. Token side-effects (refill
 * on upgrade) go through TokenService so the wallet stays consistent. The controller
 * never mutates plan columns directly — it calls this.
 */
class SubscriptionService
{
    public function __construct(
        private BillingProvider $billing,
        private TokenService $tokens,
    ) {}

    public function startCheckout(User $user, string $successUrl, string $cancelUrl): string
    {
        return $this->billing->createCheckout($user, $successUrl, $cancelUrl);
    }

    public function requestCancel(User $user): bool
    {
        $ok = $this->billing->cancel($user);
        if ($ok) {
            // Keep access until period end; the subscription.deleted webhook later
            // performs the actual downgrade.
            $this->setStatus($user, SubscriptionStatus::CANCELLED, 'cancel');
        }

        return $ok;
    }

    /** Activate premium (called from the verified webhook). Idempotent. */
    public function activatePremium(User $user, ?string $subscriptionId, ?string $customerId, ?Carbon $expiresAt, string $reason = 'webhook'): void
    {
        $this->transition($user, SubscriptionPlan::PREMIUM, SubscriptionStatus::ACTIVE, $reason, $subscriptionId, function (User $u) use ($subscriptionId, $customerId, $expiresAt) {
            $u->forceFill([
                'stripe_subscription_id'  => $subscriptionId ?? $u->stripe_subscription_id,
                'stripe_customer_id'      => $customerId ?? $u->stripe_customer_id,
                'subscription_expires_at' => $expiresAt,
            ])->save();
        });

        // Top the wallet up to the premium allowance on activation.
        $this->tokens->refillMonthly($user->refresh());
    }

    /** Payment failing — retain access briefly so they can fix their card. */
    public function markGrace(User $user): void
    {
        $this->setStatus($user, SubscriptionStatus::GRACE, 'webhook');
    }

    /** Subscription ended — drop to member and reset the wallet to the member allowance. */
    public function downgradeToMember(User $user, string $reason = 'expire'): void
    {
        $this->transition($user, SubscriptionPlan::MEMBER, SubscriptionStatus::ACTIVE, $reason, null, function (User $u) {
            $u->forceFill([
                'stripe_subscription_id'  => null,
                'subscription_expires_at' => null,
            ])->save();
        });

        $this->tokens->refillMonthly($user->refresh());
    }

    /** Apply a verified Stripe event. Unknown types are ignored. */
    public function handleStripeEvent(string $type, object $object): void
    {
        $user = $this->resolveUser($object);
        if (! $user) {
            Log::warning('Stripe subscription event with no resolvable user', ['type' => $type]);

            return;
        }

        switch ($type) {
            case 'checkout.session.completed':
                $this->activatePremium(
                    $user,
                    $object->subscription ?? null,
                    $object->customer ?? null,
                    null,
                    'checkout',
                );
                break;

            case 'customer.subscription.updated':
                $status = $object->status ?? '';
                $expires = isset($object->current_period_end)
                    ? Carbon::createFromTimestamp($object->current_period_end) : null;
                if (in_array($status, ['active', 'trialing'], true)) {
                    $this->activatePremium($user, $object->id ?? null, $object->customer ?? null, $expires);
                } elseif ($status === 'past_due') {
                    $this->markGrace($user);
                }
                break;

            case 'customer.subscription.deleted':
                $this->downgradeToMember($user, 'webhook');
                break;

            case 'invoice.payment_failed':
                $this->markGrace($user);
                break;
        }
    }

    /** Find the user a Stripe object belongs to via metadata, then stored ids. */
    private function resolveUser(object $object): ?User
    {
        $userId = $object->metadata->user_id
            ?? $object->client_reference_id
            ?? null;
        if ($userId && ($u = User::find($userId))) {
            return $u;
        }
        if (! empty($object->customer)) {
            return User::where('stripe_customer_id', $object->customer)->first();
        }

        return null;
    }

    private function setStatus(User $user, SubscriptionStatus $status, string $reason): void
    {
        $user->forceFill(['subscription_status' => $status->value])->save();
        $this->recordHistory($user, $user->subscription_plan, $user->subscription_plan, $reason, null);
    }

    /** Change plan + status atomically and log the transition. */
    private function transition(User $user, SubscriptionPlan $plan, SubscriptionStatus $status, string $reason, ?string $paymentRef, ?callable $extra = null): void
    {
        DB::transaction(function () use ($user, $plan, $status, $reason, $paymentRef, $extra) {
            $old = $user->subscription_plan;
            $user->forceFill([
                'subscription_plan'   => $plan->value,
                'subscription_status' => $status->value,
            ])->save();

            if ($extra) {
                $extra($user);
            }
            $this->recordHistory($user, $old, $plan->value, $reason, $paymentRef);
        });
    }

    private function recordHistory(User $user, ?string $old, string $new, string $reason, ?string $paymentRef): void
    {
        SubscriptionHistory::create([
            'user_id'     => $user->id,
            'old_plan'    => $old,
            'new_plan'    => $new,
            'reason'      => $reason,
            'payment_ref' => $paymentRef,
            'created_at'  => Carbon::now(),
        ]);
    }
}
