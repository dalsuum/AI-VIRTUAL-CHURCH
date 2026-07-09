<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared Bible reading (v1.3 Phase D): a session COORDINATES a group around an
 * EXISTING reading plan — it owns no reading progress (that stays on
 * user_reading_plans, mutated only by ReadingPlanService). One open session per
 * group; state machine planned → active ⇄ paused → completed (+ abandoned),
 * mutated only by ReadingSessionService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('correlation_id');
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reading_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['planned', 'active', 'paused', 'completed', 'abandoned'])
                  ->default('planned');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'status']);   // "the group's open session"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_sessions');
    }
};
