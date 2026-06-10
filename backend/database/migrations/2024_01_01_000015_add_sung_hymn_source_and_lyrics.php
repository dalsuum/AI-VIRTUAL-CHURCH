<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Split the hymn source into two presentations and make the SUNG one the default:
     *   hymn_sung -> a public-domain sung recording (real voices) — the default.
     *   hymn      -> the instrumental render + on-screen lyrics (added previously).
     * Also add a `lyrics` column on service_assets so the worship segment can carry
     * the hymn's public-domain verses for the player to display.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY music_source ENUM('hymn_sung', 'hymn', 'suno', 'youtube') NOT NULL DEFAULT 'hymn_sung'");
        DB::statement("ALTER TABLE service_sessions MODIFY music_source ENUM('hymn_sung', 'hymn', 'suno', 'youtube') NOT NULL DEFAULT 'hymn_sung'");

        Schema::table('service_assets', function (Blueprint $table) {
            $table->longText('lyrics')->nullable()->after('text_payload');
        });
    }

    public function down(): void
    {
        Schema::table('service_assets', function (Blueprint $table) {
            $table->dropColumn('lyrics');
        });

        // Collapse the sung default back to the instrumental hymn before narrowing.
        DB::statement("UPDATE users SET music_source = 'hymn' WHERE music_source = 'hymn_sung'");
        DB::statement("UPDATE service_sessions SET music_source = 'hymn' WHERE music_source = 'hymn_sung'");
        DB::statement("ALTER TABLE users MODIFY music_source ENUM('hymn', 'suno', 'youtube') NOT NULL DEFAULT 'hymn'");
        DB::statement("ALTER TABLE service_sessions MODIFY music_source ENUM('hymn', 'suno', 'youtube') NOT NULL DEFAULT 'hymn'");
    }
};
