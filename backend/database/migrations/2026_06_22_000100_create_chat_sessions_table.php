<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The unified history spine. Every interaction across every module (Bible
        // Study, Worship, Church Service, Pastor Chat, …) gets exactly ONE row here
        // so the ChatGPT-style sidebar is a single owner-scoped query. Module-specific
        // mechanics live in their own tables and link back via chat_session_id.
        //
        // Reads are ALWAYS owner-scoped (user_id === auth id) — no cross-user access.
        // UUID id so session links are unguessable and shardable to millions of rows.
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('session_type', [
                'bible_study', 'prayer', 'music', 'service', 'pastor', 'devotion', 'general',
            ]);
            $table->string('title', 200)->nullable();
            $table->string('language', 12)->nullable();
            $table->enum('status', ['active', 'completed', 'archived'])->default('active');
            $table->text('summary')->nullable();
            $table->string('mood', 40)->nullable();
            $table->boolean('pinned')->default(false);
            $table->boolean('favorite')->default(false);
            $table->boolean('archived')->default(false);
            $table->unsignedTinyInteger('rating')->nullable();      // 1–5, user feedback
            // SSE stream token (sha256 HASH only) for live modules (e.g. Pastor Chat).
            $table->char('stream_token', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'last_activity_at']);          // sidebar ordering
            $table->index(['user_id', 'session_type']);              // type filter
            $table->index(['user_id', 'pinned']);                    // pinned section
        });

        // Full-text search over title + summary (MySQL/InnoDB). Guarded so the suite
        // still runs on drivers without FULLTEXT (sqlite); search degrades to LIKE.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE chat_sessions ADD FULLTEXT chat_sessions_fulltext (title, summary)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_sessions');
    }
};
