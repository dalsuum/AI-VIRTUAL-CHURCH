<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user's contextual role inside a church. Role lives HERE, not on users — a user
 * can hold different roles across churches. Policies key off this row's role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('church_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['guest', 'member', 'leader', 'deacon', 'elder', 'pastor', 'owner'])
                  ->default('member');
            $table->enum('status', ['active', 'invited', 'inactive'])->default('active');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['church_id', 'user_id']);   // one membership per church
            $table->index(['user_id', 'status']);        // "my active churches"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('church_memberships');
    }
};
