<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_intakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->unique()
                  ->constrained('service_sessions')->cascadeOnDelete();
            $table->string('mood', 100);
            $table->text('prayer_text')->nullable();

            // Filled in by the AI pipeline after intake is processed.
            $table->string('scripture_ref', 128)->nullable();
            $table->text('music_prompt')->nullable();   // used for Suno generation
            $table->string('music_query', 255)->nullable(); // used for YouTube search

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_intakes');
    }
};
