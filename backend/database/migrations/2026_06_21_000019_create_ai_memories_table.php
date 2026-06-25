<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Owner-scoped memory store behind the Memory Engine's strategies
        // (window | summary | semantic). Every read filters on module + owner
        // (user_id XOR guest_session_id) — no cross-session/cross-user recall.
        // `embedding` is reserved for a future vector backend.
        Schema::create('ai_memories', function (Blueprint $table) {
            $table->id();
            $table->string('module', 64);
            $table->foreignId('session_id')->nullable()
                  ->constrained('study_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('guest_session_id', 64)->nullable();
            $table->enum('kind', ['window', 'summary', 'semantic']);
            $table->text('content');
            $table->json('embedding')->nullable();               // future vector backend
            $table->timestamp('created_at')->useCurrent();

            $table->index(['module', 'user_id', 'created_at']);
            $table->index(['module', 'guest_session_id', 'created_at']);
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_memories');
    }
};
