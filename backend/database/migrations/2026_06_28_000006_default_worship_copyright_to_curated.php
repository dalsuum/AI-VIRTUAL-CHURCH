<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Worship Radio no longer persists auto-discovered YouTube results — the
 * catalogue is curated-only (see MusicRecommendationService). The legacy
 * `metadata_only` column default meant "auto-discovered"; with discovery rows
 * gone, an admin-created track must NOT default to that value, or a future
 * "delete discovered rows" cleanup (DELETE … WHERE copyright_status =
 * 'metadata_only') would take curated songs with it. Re-default to `curated`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worship_tracks', function (Blueprint $table) {
            $table->string('copyright_status')->default('curated')->change();
        });
    }

    public function down(): void
    {
        Schema::table('worship_tracks', function (Blueprint $table) {
            $table->string('copyright_status')->default('metadata_only')->change();
        });
    }
};
