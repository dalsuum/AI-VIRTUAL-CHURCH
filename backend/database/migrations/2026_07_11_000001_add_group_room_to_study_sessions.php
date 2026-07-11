<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group Bible study rooms (v1.4): a leader shares THEIR OWN study session with a
 * ministry group — members read along and ask questions in the same AI-taught
 * conversation. Billing is unchanged by design (owner decision: creator pays —
 * the pipeline already reserves from the session owner). sender_id records which
 * human wrote each message, ready for attribution display.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('user_id')
                  ->constrained()->nullOnDelete();
        });
        Schema::table('study_messages', function (Blueprint $table) {
            $table->foreignId('sender_id')->nullable()->after('role')
                  ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('study_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sender_id');
        });
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
        });
    }
};
