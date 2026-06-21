<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A Bible Study discussion session. Ownership is user_id XOR
        // guest_session_id (enforced in the application layer / policy). Reads are
        // ALWAYS owner-scoped — no cross-user retrieval.
        //
        // `stream_token` is stored as a SHA-256 HASH only (never the plaintext):
        // CSPRNG-generated, returned to the owner once, constant-time compared on
        // SSE open, rotated on close/regenerate. `owner_fingerprint` is a soft
        // signal (mismatch → log + step-up, not a hard block).
        Schema::create('study_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('guest_session_id', 64)->nullable();
            $table->string('language', 12);
            $table->string('translation', 12);
            $table->string('style', 40)->nullable();
            $table->string('topic', 160)->nullable();
            $table->string('mood', 40)->nullable();
            $table->unsignedTinyInteger('agent_count');          // bounded 2–7 in validation
            $table->enum('state', [
                'created', 'framing', 'discussing', 'awaiting_user',
                'ending', 'summarized', 'closed',
            ])->default('created');
            $table->char('stream_token', 64)->unique();          // 🔒 sha256 hash only
            $table->string('owner_fingerprint', 64)->nullable(); // soft device/network bind
            $table->string('contact_email', 190)->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['guest_session_id', 'created_at']);
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_sessions');
    }
};
