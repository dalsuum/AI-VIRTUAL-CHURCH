<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a Hebrew gloss to the vocabulary reference now that the Hebrew Tanakh
 * (WLC) is a Bible-reader translation alongside en/my/td. Like `burmese`, the
 * column is optional — entries without a Hebrew equivalent simply leave it null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vocabularies', function (Blueprint $table) {
            $table->string('hebrew')->nullable()->after('burmese');
        });
    }

    public function down(): void
    {
        Schema::table('vocabularies', function (Blueprint $table) {
            $table->dropColumn('hebrew');
        });
    }
};
