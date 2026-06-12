<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service language ('en' | 'my'). Chosen on the intake form's language tab and
 * locked per session, like music_source: every downstream consumer — the LLM
 * prompts, the Bible translation (BSB vs Judson 1835), the hymn library
 * (Open Hymnal vs dalsuum/myanmar-hymns), and the edge-tts voice — keys off it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_sessions', function (Blueprint $table) {
            $table->string('language', 5)->default('en')->after('music_source');
        });
    }

    public function down(): void
    {
        Schema::table('service_sessions', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
