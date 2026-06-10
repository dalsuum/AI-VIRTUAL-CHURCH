<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'hymn' as a music source and make it the default. Hymns are free,
     * public-domain tracks rendered to local MP3s ahead of time (see
     * workers/seed_hymns.py) — no AI credit, no provider call at service time.
     *
     * Both columns gain the value: users.music_source (the per-user preference)
     * and service_sessions.music_source (locked once per service at start).
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY music_source ENUM('hymn', 'suno', 'youtube') NOT NULL DEFAULT 'hymn'");
        DB::statement("ALTER TABLE service_sessions MODIFY music_source ENUM('hymn', 'suno', 'youtube') NOT NULL DEFAULT 'hymn'");
    }

    public function down(): void
    {
        // Move any 'hymn' rows back to 'suno' first so they stay valid under the
        // narrowed enum, then restore the original column definition and default.
        DB::statement("UPDATE users SET music_source = 'suno' WHERE music_source = 'hymn'");
        DB::statement("UPDATE service_sessions SET music_source = 'suno' WHERE music_source = 'hymn'");
        DB::statement("ALTER TABLE users MODIFY music_source ENUM('suno', 'youtube') NOT NULL DEFAULT 'suno'");
        DB::statement("ALTER TABLE service_sessions MODIFY music_source ENUM('suno', 'youtube') NOT NULL DEFAULT 'suno'");
    }
};
