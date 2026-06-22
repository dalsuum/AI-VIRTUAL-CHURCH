<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Turn-by-turn conversation for chat-style sessions (Pastor Chat today;
        // any future single/multi-assistant module). Bible Study keeps its own
        // study_messages engine; only its summary/title is mirrored to chat_sessions.
        //
        // `content` of user/assistant turns is conversation DATA only — it never
        // feeds system/persona/provider config. Narrow + a covering (session_id,
        // created_at) index so rendering a session is one index range scan.
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id');
            $table->foreign('session_id')->references('id')->on('chat_sessions')->cascadeOnDelete();
            $table->enum('sender', ['user', 'assistant', 'system']);
            $table->string('message_type', 24)->default('text');   // text|scripture|audio|…
            // Encrypted at rest via the model cast — pastoral conversation is sensitive.
            $table->text('content');
            $table->json('metadata')->nullable();                  // scripture refs, audio, …
            $table->unsignedInteger('token_usage')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['session_id', 'created_at']);           // covering render query
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
