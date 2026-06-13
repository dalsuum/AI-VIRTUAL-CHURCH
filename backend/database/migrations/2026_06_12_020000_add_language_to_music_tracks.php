<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('music_tracks', function (Blueprint $table) {
            $table->string('language', 5)->default('en')->after('mood')->index();
            $table->index(['language', 'mood']);
        });

        DB::table('music_tracks')->whereNull('language')->update(['language' => 'en']);
    }

    public function down(): void
    {
        Schema::table('music_tracks', function (Blueprint $table) {
            $table->dropIndex(['language', 'mood']);
            $table->dropColumn('language');
        });
    }
};
