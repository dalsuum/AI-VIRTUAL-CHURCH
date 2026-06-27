<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reading plan is an ordered sequence of days, each with one or more scripture
 * passages. Plans are pure DATA (seeded), not code — new plans (NT in 90, Psalms in
 * 30, Chronological, Advent/Lent) are inserts, never new logic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('language', 8)->nullable();   // null = language-agnostic refs
            $table->unsignedSmallInteger('day_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_plans');
    }
};
