<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-language delivery mode for each special Sunday's sermon and music:
 *
 *   { "sermon": {"en":"auto","my":"manual","td":"auto"},
 *     "music":  {"en":"auto","my":"auto","td":"manual"} }
 *
 * 'auto'   → the AI sermon / mood-biased worship runs as normal (default).
 * 'manual' → serve the highest-priority active curated entry (special_sermons /
 *            special_songs) for that day+language; if none is active, the worker
 *            safely falls back to 'auto' so a service is never left empty.
 *
 * Null/absent keys default to 'auto', so existing rows behave exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('special_sundays', function (Blueprint $table) {
            $table->json('content_modes')->nullable()->after('music_moods');
        });
    }

    public function down(): void
    {
        Schema::table('special_sundays', function (Blueprint $table) {
            $table->dropColumn('content_modes');
        });
    }
};
