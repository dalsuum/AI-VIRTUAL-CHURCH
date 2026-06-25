<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One summary per session, produced at End Discussion. This is the only
        // model output permitted to enter long-term memory (moderator-synthesized),
        // keeping intermediate pastor variations out of recall.
        Schema::create('study_summaries', function (Blueprint $table) {
            $table->foreignId('session_id')->primary()
                  ->constrained('study_sessions')->cascadeOnDelete();
            $table->json('key_verses')->nullable();
            $table->json('lessons')->nullable();
            $table->text('prayer')->nullable();
            $table->json('action_points')->nullable();
            $table->json('reflection_questions')->nullable();
            $table->json('study_plan')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_summaries');
    }
};
