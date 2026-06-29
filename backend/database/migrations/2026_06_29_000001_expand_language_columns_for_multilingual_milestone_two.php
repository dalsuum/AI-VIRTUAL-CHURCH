<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('special_sermons', function (Blueprint $table) {
            $table->string('language', 5)->change();
        });

        Schema::table('special_songs', function (Blueprint $table) {
            $table->string('language', 5)->change();
        });

        DB::statement(
            "ALTER TABLE worship_tracks MODIFY language ENUM('en','my','td','fr','de','es','ja','zh-CN','ko','hi','ta','th') NOT NULL DEFAULT 'en'"
        );
    }

    public function down(): void
    {
        DB::table('worship_tracks')->whereNotIn('language', ['en', 'my', 'td'])->update(['language' => 'en']);
        DB::table('special_sermons')->whereNotIn('language', ['en', 'my', 'td'])->update(['language' => 'en']);
        DB::table('special_songs')->whereNotIn('language', ['en', 'my', 'td'])->update(['language' => 'en']);

        DB::statement(
            "ALTER TABLE worship_tracks MODIFY language ENUM('en','my','td') NOT NULL DEFAULT 'en'"
        );

        Schema::table('special_sermons', function (Blueprint $table) {
            $table->string('language', 2)->change();
        });

        Schema::table('special_songs', function (Blueprint $table) {
            $table->string('language', 2)->change();
        });
    }
};
