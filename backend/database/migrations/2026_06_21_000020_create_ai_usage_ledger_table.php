<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-turn token + cost telemetry, written from the /internal/study-turn
        // webhook. Scalar columns (not JSON) so admin cost analytics aggregate
        // cheaply. A range-partition-by-month candidate at scale; cost stored as
        // integer micros to avoid float drift.
        Schema::create('ai_usage_ledger', function (Blueprint $table) {
            $table->id();
            $table->string('module', 64);
            $table->foreignId('session_id')->nullable()
                  ->constrained('study_sessions')->nullOnDelete();
            $table->string('provider', 40)->nullable();
            $table->string('model', 160)->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['module', 'created_at']);
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_ledger');
    }
};
