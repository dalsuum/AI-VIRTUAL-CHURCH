<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user's enrollment in a plan. PROGRESS-anchored, not calendar-anchored:
 * `current_sequence` is the next day to read and only advances when the user marks a
 * day complete — so missing real-world days never skips content. "Today's reading" is
 * always the day at `current_sequence`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_reading_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reading_plan_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['active', 'completed', 'abandoned'])->default('active');
            $table->unsignedSmallInteger('current_sequence')->default(1);
            $table->date('started_on');
            $table->date('last_read_on')->nullable();      // local date of last completion
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);          // "my active plan"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_reading_plans');
    }
};
