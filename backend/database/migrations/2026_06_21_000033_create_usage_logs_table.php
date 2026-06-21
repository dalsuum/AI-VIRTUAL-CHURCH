<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational usage log — one row per AI request, for cost forensics and debugging
 * ("why did the model bill spike yesterday?"). Distinct from token_ledger (which is a
 * wallet audit) and ai_usage_ledger (per-turn study telemetry): this is the unified,
 * user-attributed request record across services. `cost_micros` mirrors the millionths
 * convention used elsewhere to avoid float drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_ref', 64)->nullable();   // hashed guest identity when anonymous
            $table->string('service', 48);                  // study|service|...
            $table->string('model', 160)->nullable();
            $table->unsignedInteger('tokens')->default(0);  // wallet tokens charged
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('status', 16)->default('ok');    // ok|failed|refunded
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['service', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_logs');
    }
};
