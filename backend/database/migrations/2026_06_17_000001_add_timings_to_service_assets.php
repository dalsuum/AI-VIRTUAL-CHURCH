<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LRC synchronized lyrics: an optional per-line timing track for a sung hymn,
 * stored as JSON ([{time, line_index}]) next to the `lyrics` it lines up with.
 * Additive + nullable — segments without synced lyrics are unaffected and keep
 * the plain on-screen verses. See docs/lrc-static-sync-spike.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_assets', function (Blueprint $table) {
            $table->json('timings')->nullable()->after('lyrics');
        });
    }

    public function down(): void
    {
        Schema::table('service_assets', function (Blueprint $table) {
            $table->dropColumn('timings');
        });
    }
};
