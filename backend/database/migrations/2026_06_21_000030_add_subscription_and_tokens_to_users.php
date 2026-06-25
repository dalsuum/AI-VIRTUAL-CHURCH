<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription + token-wallet state for the tiered model.
 *
 * `subscription_plan` (not a boolean is_premium) is the extensible axis — today it is
 * one of guest|member|premium, but the column tolerates premium_yearly / church /
 * enterprise without a schema change. Plan *rules* (token grant, ad-free, max pastors)
 * live in App\Services\PlanService, never hard-coded against this string.
 *
 * The token wallet is a single authoritative balance; `monthly_allowance` records the
 * current plan's grant so the dashboard can show "used / remaining". Every balance
 * mutation is also written to token_ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Billing tier. Distinct from `role` (which governs admin/staff privilege).
            $table->string('subscription_plan', 32)->default('member')->after('role');
            // Lifecycle state, kept explicit rather than inferred from dates so "premium
            // but payment failing" (grace) is unambiguous everywhere. See SubscriptionStatus.
            $table->string('subscription_status', 16)->default('active')->after('subscription_plan');
            $table->timestamp('subscription_expires_at')->nullable()->after('subscription_status');
            $table->string('stripe_customer_id', 64)->nullable()->after('subscription_expires_at');
            $table->string('stripe_subscription_id', 64)->nullable()->after('stripe_customer_id');

            // Spendable AI tokens. Guests never accrue a balance (single free use per
            // service, tracked by GuestUsageService); members/premium are topped up
            // monthly by the tokens:refill-monthly command.
            $table->unsignedInteger('token_balance')->default(0)->after('stripe_subscription_id');
            $table->unsignedInteger('monthly_allowance')->default(0)->after('token_balance');
            $table->timestamp('tokens_refilled_at')->nullable()->after('monthly_allowance');

            $table->index('subscription_plan');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['subscription_plan']);
            $table->dropColumn([
                'subscription_plan', 'subscription_status', 'subscription_expires_at',
                'stripe_customer_id', 'stripe_subscription_id', 'token_balance',
                'monthly_allowance', 'tokens_refilled_at',
            ]);
        });
    }
};
