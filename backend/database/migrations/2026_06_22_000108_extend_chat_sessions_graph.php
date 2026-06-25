<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phase 1 of SessionStateStore (see docs/session-state-store.md). Turns the
        // chat_sessions spine into a graph node: lineage (root/parent) + an explicit
        // active pointer that replaces the "latest row = current state" assumption.
        // These are plain indexed UUID columns, NOT foreign keys: node→session and
        // session→node would form a circular FK, and a fork's active_node_id legitimately
        // points into the PARENT session's node.
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->uuid('root_session_id')->nullable()->after('user_id');
            $table->uuid('parent_session_id')->nullable()->after('root_session_id');
            $table->uuid('parent_node_id')->nullable()->after('parent_session_id');
            $table->uuid('active_node_id')->nullable()->after('parent_node_id');

            $table->index('root_session_id');
            $table->index('parent_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropIndex(['root_session_id']);
            $table->dropIndex(['parent_session_id']);
            $table->dropColumn(['root_session_id', 'parent_session_id', 'parent_node_id', 'active_node_id']);
        });
    }
};
