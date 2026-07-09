<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who reads along in a shared session. user_reading_plan_id REFERENCES the member's
 * own enrollment — the "never a second reading model" invariant made concrete:
 * a participant's progress in the session IS their individual plan progress.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('reading_session_id')->constrained('reading_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_reading_plan_id')->constrained('user_reading_plans')->cascadeOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['reading_session_id', 'user_id']);   // one seat per member
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_participants');
    }
};
