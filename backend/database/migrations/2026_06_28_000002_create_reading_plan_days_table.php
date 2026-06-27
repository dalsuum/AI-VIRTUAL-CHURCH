<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One day of a plan. `sequence` is the ordinal (1..day_count); `slug` is a STABLE
 * identifier (e.g. "day-001") that survives display-metadata changes, so bookmarks,
 * deep links, notifications, AI reflections, analytics and shared-reading invitations
 * can reference a day durably without pinning to an integer position.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_plan_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reading_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('slug');                 // stable per-day id, unique within plan
            $table->string('title')->nullable();
            $table->json('passages');               // [{book, chapter}, …]
            $table->timestamps();

            $table->unique(['reading_plan_id', 'sequence']);
            $table->unique(['reading_plan_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_plan_days');
    }
};
