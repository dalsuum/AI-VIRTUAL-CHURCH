<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The durable truth for session state (SessionStateStore). A message is just a
        // node with type=message — nodes are a SUPERSET of messages. The graph
        // (parent_node_id) enables branching; the (branch_id, seq) pair keeps the active
        // LINEAR branch a single indexed range scan, so the common read stays O(1)-indexed
        // at scale (the same shape as study_messages(session_id, turn)).
        //
        // `content` is encrypted at rest (model cast) — conversation can be deeply personal.
        // `parent_node_id` is an unconstrained UUID: it may cross sessions (a fork's first
        // node points back into its parent session's node).
        Schema::create('session_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('session_id');
            $table->foreign('session_id')->references('id')->on('chat_sessions')->cascadeOnDelete();
            $table->uuid('parent_node_id')->nullable();
            $table->uuid('branch_id');
            $table->unsignedBigInteger('seq');
            $table->enum('type', ['message', 'checkpoint', 'fork', 'system_event'])->default('message');
            $table->string('sender', 24)->nullable();        // queryable: user|assistant|moderator|…
            $table->text('content')->nullable();             // encrypted
            $table->json('metadata')->nullable();
            $table->unsignedInteger('token_usage')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Active-branch read: range scan over one branch in order.
            $table->index(['session_id', 'branch_id', 'seq']);
            $table->index('parent_node_id');
            // Monotonic position within a branch — also guards against duplicate seq.
            $table->unique(['branch_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_nodes');
    }
};
