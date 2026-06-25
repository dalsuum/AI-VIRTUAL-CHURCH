<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Streaming-metadata catalog that powers the AI Worship Radio (mood-based
 * recommendations). This is DISTINCT from the `songs` table (ChordPro lyrics
 * library): rows here carry no hosted audio — only metadata and OFFICIAL
 * streaming links (YouTube/Spotify/Apple), per the copyright-safe rule.
 *
 * `themes`/`moods`/`scriptures` are JSON tag arrays used by the deterministic
 * MusicRecommendationService scorer (language 40 / mood 30 / theme 20 /
 * popularity 10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worship_tracks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('artist')->nullable();
            $table->enum('language', ['en', 'my', 'td'])->default('en')->index();
            $table->string('genre')->nullable();
            $table->json('themes')->nullable();
            $table->json('moods')->nullable();
            $table->json('scriptures')->nullable();
            $table->unsignedInteger('duration')->nullable();   // seconds
            $table->string('youtube_url')->nullable();
            $table->string('spotify_url')->nullable();
            $table->string('apple_music_url')->nullable();
            $table->string('cover_image')->nullable();
            $table->boolean('lyrics_available')->default(false);
            $table->string('copyright_status')->default('metadata_only');
            $table->unsignedInteger('popularity')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();

            $table->index(['language', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worship_tracks');
    }
};
