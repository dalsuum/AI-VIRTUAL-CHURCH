<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY music_source ENUM('hymn_sung', 'hymn', 'hymn_youtube', 'suno', 'youtube') NOT NULL DEFAULT 'hymn_sung'");
        DB::statement("ALTER TABLE service_sessions MODIFY music_source ENUM('hymn_sung', 'hymn', 'hymn_youtube', 'suno', 'youtube') NOT NULL DEFAULT 'hymn_sung'");
    }

    public function down(): void
    {
        DB::statement("UPDATE users SET music_source = 'hymn_sung' WHERE music_source = 'hymn_youtube'");
        DB::statement("UPDATE service_sessions SET music_source = 'hymn_sung' WHERE music_source = 'hymn_youtube'");
        DB::statement("ALTER TABLE users MODIFY music_source ENUM('hymn_sung', 'hymn', 'suno', 'youtube') NOT NULL DEFAULT 'hymn_sung'");
        DB::statement("ALTER TABLE service_sessions MODIFY music_source ENUM('hymn_sung', 'hymn', 'suno', 'youtube') NOT NULL DEFAULT 'hymn_sung'");
    }
};
