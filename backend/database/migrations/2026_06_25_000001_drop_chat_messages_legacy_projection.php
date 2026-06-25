<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SessionStateStore Phase 4: drop the legacy chat_messages projection. session_nodes is
 * now the sole durable record of conversation turns; all writers (HistoryService, pastor
 * webhook) and readers (history show/export, pastor/title/journal dispatchers) go through
 * SessionStateStore. `down()` recreates the table schema (data is not restored — nodes are
 * the source of truth; backfill from session_nodes if a rebuild is ever needed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('chat_messages');
    }

    public function down(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id');
            $table->foreign('session_id')->references('id')->on('chat_sessions')->cascadeOnDelete();
            $table->enum('sender', ['user', 'assistant', 'system']);
            $table->string('message_type', 24)->default('text');
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->unsignedInteger('token_usage')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['session_id', 'created_at']);
        });
    }
};
