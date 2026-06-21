<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable trail of every plan transition (upgrade, downgrade, cancel, expiry,
 * reactivation). Answers "what did this user have, and when?" for support and auditing
 * without reconstructing it from Stripe. Written by SubscriptionService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('old_plan', 32)->nullable();
            $table->string('new_plan', 32);
            $table->string('reason', 48);                 // checkout|cancel|expire|webhook|admin
            $table->string('payment_ref', 120)->nullable(); // Stripe subscription/invoice id
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_history');
    }
};
