<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user's contextual role inside a group. Mirrors church_memberships: role lives
 * HERE, not on users — a user can lead the choir and merely attend Bible study.
 * Policies key off this row's role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['member', 'leader'])->default('member');
            $table->enum('status', ['active', 'invited', 'inactive'])->default('active');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'user_id']);   // one membership per group
            $table->index(['user_id', 'status']);       // "my active groups"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_memberships');
    }
};
