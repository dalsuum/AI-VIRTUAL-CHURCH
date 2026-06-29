<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE worship_tracks MODIFY language ENUM('en','my','td','fr','de','es','ja','zh-CN','ko','hi','ta','th','ar','he') NOT NULL DEFAULT 'en'"
        );
    }

    public function down(): void
    {
        DB::table('worship_tracks')->whereIn('language', ['ar', 'he'])->update(['language' => 'en']);

        DB::statement(
            "ALTER TABLE worship_tracks MODIFY language ENUM('en','my','td','fr','de','es','ja','zh-CN','ko','hi','ta','th') NOT NULL DEFAULT 'en'"
        );
    }
};
