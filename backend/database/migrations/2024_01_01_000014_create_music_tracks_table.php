<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reusable library of AI-composed worship songs, keyed by mood. Every fresh
        // Suno generation is registered here; when a worshipper is new to a mood we
        // serve them a random existing track instead of composing (and paying for) a
        // new one. `storage_key` is the RAW object key (e.g. worship/<taskId>.mp3) —
        // the worker re-presigns it at playback so S3 URLs never serve stale/expired.
        Schema::create('music_tracks', function (Blueprint $table) {
            $table->id();
            $table->string('mood')->index();           // one of the fixed intake moods
            $table->string('provider_ref')->unique();  // Suno taskId — dedupes the pool
            $table->string('storage_key');             // raw object key, not a presigned URL
            $table->string('title')->nullable();
            $table->string('source')->default('suno');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_tracks');
    }
};
