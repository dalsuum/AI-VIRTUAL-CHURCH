<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cold store / last-seen fallback for presence. The hot path (heartbeats, "who's
 * online") will live in Redis from Phase 6; this table is the durable last-known
 * state and the source of truth when Redis is unavailable. One row per user.
 *
 * activity_ref is a nullable UUID pointing at the spine session the user is in
 * (chat_sessions.id) — kept loose (no FK) so presence never blocks on session GC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('status', ['online', 'offline'])->default('offline');
            $table->enum('current_activity', ['reading', 'studying', 'worship', 'pastor', 'radio'])->nullable();
            $table->uuid('activity_ref')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presences');
    }
};
