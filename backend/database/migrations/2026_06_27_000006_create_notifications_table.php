<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's standard notifications table (the in-app inbox / "database" channel),
 * extended with two community-platform columns:
 *   - priority       : drives client treatment (sound/heads-up) and is set from the
 *                      notification's NotificationPriority.
 *   - correlation_id : shared across an invitation → session → notification → audit →
 *                      analytics workflow so the whole chain is traceable, and used by
 *                      listeners as the idempotency key to avoid duplicate delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->enum('priority', ['critical', 'high', 'normal', 'low'])->default('normal');
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']); // unread badge
            $table->index('correlation_id');                                 // workflow trace + dedupe
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
