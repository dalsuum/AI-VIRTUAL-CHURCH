<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('music_tracks', function (Blueprint $table) {
            $table->text('lyrics')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('music_tracks', function (Blueprint $table) {
            $table->dropColumn('lyrics');
        });
    }
};
