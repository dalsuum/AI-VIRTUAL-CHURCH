<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable reading-streak counters, maintained by the UpdateReadingStreak listener on
 * ReadingDayCompleted. Kept separate from enrollment so streaks survive plan changes
 * and span multiple plans.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_streaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('current_streak')->default(0);
            $table->unsignedInteger('longest_streak')->default(0);
            $table->date('last_read_on')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_streaks');
    }
};
