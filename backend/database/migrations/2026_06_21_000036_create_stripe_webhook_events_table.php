<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-level Stripe webhook de-duplication. Stripe redelivers events (and two deliveries
 * can interleave); state-guards alone can both pass before either commits. A UNIQUE
 * `event_id` makes "process exactly once" enforceable by the database: the handler
 * inserts the event id and runs the side effects in the SAME transaction
 * (App\Services\StripeEventGuard), so a duplicate delivery fails the unique insert and
 * does no work, while a handler that throws rolls back the marker too (safe to retry).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();   // Stripe "evt_..." id
            $table->string('type', 80);
            $table->timestamp('processed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
    }
};
