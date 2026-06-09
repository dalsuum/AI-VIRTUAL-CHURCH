<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_assets', function (Blueprint $table) {
            // Text-to-speech narration of a spoken segment. Independent of storage_key
            // (which carries an avatar video) so a segment can have text, a video, and
            // narration audio all at once — each filled in its own webhook pass.
            $table->string('audio_key', 512)->nullable()->after('storage_key');
        });
    }

    public function down(): void
    {
        Schema::table('service_assets', function (Blueprint $table) {
            $table->dropColumn('audio_key');
        });
    }
};
