<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-type metadata, each 1:1 with a chat_sessions row. Keeping these out of
        // the spine keeps the sidebar query lean while preserving rich, type-specific
        // detail for resume + the Spiritual Journey dashboard.

        // Bible Study — bridges to the live multi-agent engine (study_sessions).
        Schema::create('bible_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('chat_session_id')->unique();
            $table->foreign('chat_session_id')->references('id')->on('chat_sessions')->cascadeOnDelete();
            $table->foreignId('study_session_id')->nullable()
                  ->constrained('study_sessions')->nullOnDelete();
            $table->string('book', 40)->nullable();
            $table->unsignedSmallInteger('chapter')->nullable();
            $table->string('verses', 60)->nullable();
            $table->string('translation', 12)->nullable();
            $table->text('discussion_summary')->nullable();
            $table->timestamps();
        });

        // Worship Radio — a listening session (grouped per day + mood).
        Schema::create('music_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('chat_session_id')->unique();
            $table->foreign('chat_session_id')->references('id')->on('chat_sessions')->cascadeOnDelete();
            $table->json('playlist')->nullable();
            $table->json('songs_played')->nullable();
            $table->json('liked')->nullable();
            $table->json('skipped')->nullable();
            $table->unsignedInteger('duration')->nullable();       // seconds
            $table->timestamps();
        });

        // Generated Church Service — bridges to service_sessions.
        Schema::create('service_sessions_meta', function (Blueprint $table) {
            $table->id();
            $table->uuid('chat_session_id')->unique();
            $table->foreign('chat_session_id')->references('id')->on('chat_sessions')->cascadeOnDelete();
            $table->foreignId('service_session_id')->nullable()
                  ->constrained('service_sessions')->nullOnDelete();
            $table->string('church_id', 64)->nullable();
            $table->string('service_name', 160)->nullable();
            $table->string('speaker', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Prayer sessions.
        Schema::create('prayer_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('chat_session_id')->unique();
            $table->foreign('chat_session_id')->references('id')->on('chat_sessions')->cascadeOnDelete();
            $table->json('prayer_topics')->nullable();
            $table->boolean('answered_prayer')->default(false);
            $table->boolean('private')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prayer_sessions');
        Schema::dropIfExists('service_sessions_meta');
        Schema::dropIfExists('music_sessions');
        Schema::dropIfExists('bible_sessions');
    }
};
