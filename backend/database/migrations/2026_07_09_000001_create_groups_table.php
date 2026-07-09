<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ministry groups (Bible study, youth, choir, prayer, …) scoped to a church.
 * Leadership of a group lives on group_memberships (GroupRole), NOT on ChurchRole —
 * a "worship leader" is a leader OF the worship group, keeping the church role
 * hierarchy stable as ministries are added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['bible_study', 'youth', 'children', 'women', 'men', 'choir', 'prayer', 'custom'])
                  ->default('custom');
            $table->string('description', 500)->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['church_id', 'name']);   // no duplicate group names within a church
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
