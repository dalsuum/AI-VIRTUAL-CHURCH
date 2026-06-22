<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Read-only public share links. SECURITY: `token` holds only a SHA-256 HASH of
        // the CSPRNG token handed to the owner once (mirrors study_sessions.stream_token);
        // `password` is a bcrypt hash (nullable). Expiry + revocation checked server-side.
        Schema::create('chat_session_shares', function (Blueprint $table) {
            $table->id();
            $table->uuid('chat_session_id');
            $table->foreign('chat_session_id')->references('id')->on('chat_sessions')->cascadeOnDelete();
            $table->char('token', 64)->unique();                   // sha256 hash only
            $table->string('password', 255)->nullable();           // bcrypt
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('chat_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_session_shares');
    }
};
