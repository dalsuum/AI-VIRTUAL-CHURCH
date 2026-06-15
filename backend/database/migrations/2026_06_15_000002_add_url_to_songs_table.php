<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the original source URL to songs so the imported Myanmar lyrics corpus
 * round-trips losslessly: the worker JSON export carries `url` alongside the
 * lyrics, and the DB stays the single source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->string('url')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropColumn('url');
        });
    }
};
