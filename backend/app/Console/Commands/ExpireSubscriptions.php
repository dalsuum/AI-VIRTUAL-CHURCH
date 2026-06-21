<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

/**
 * Daily safety net for premium subscriptions whose period has ended without a Stripe
 * 'subscription.deleted' webhook arriving (e.g. a missed event). Any paid plan past its
 * expiry is downgraded to member. The webhook remains the primary path; this is backstop.
 */
class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire';
    protected $description = 'Downgrade premium users whose subscription period has lapsed';

    public function handle(SubscriptionService $subscriptions): int
    {
        $count = 0;

        User::query()
            ->where('subscription_plan', SubscriptionPlan::PREMIUM->value)
            ->whereNotNull('subscription_expires_at')
            ->where('subscription_expires_at', '<', now())
            ->chunkById(200, function ($users) use ($subscriptions, &$count) {
                foreach ($users as $user) {
                    $subscriptions->downgradeToMember($user, 'expire');
                    $count++;
                }
            });

        $this->info("Expired {$count} subscription(s).");

        return self::SUCCESS;
    }
}
