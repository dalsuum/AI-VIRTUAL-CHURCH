<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group pastor rooms (v1.4): a leader opens THEIR OWN pastor conversation as
 * the group's room — members read along and speak into it, live or later.
 * One nullable ownership flag on the unified conversation spine; usable by any
 * future session type that becomes group-shareable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('user_id')
                  ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
        });
    }
};
