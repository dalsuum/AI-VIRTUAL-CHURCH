<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI-written "Spiritual Journal" entries — a reflective keepsake distilled from
        // a session (insight + prayer + reflection). Append-only and owner-scoped. The
        // link to the source session is nullOnDelete: the journal is the permanent
        // record and must outlive the conversation it came from. Reflective text is
        // encrypted at rest (model cast) — it can be deeply personal.
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('chat_session_id')->nullable();
            $table->foreign('chat_session_id')->references('id')->on('chat_sessions')->nullOnDelete();
            $table->enum('status', ['pending', 'ready', 'failed'])->default('pending');
            $table->string('title', 200)->nullable();
            $table->string('scripture_ref', 120)->nullable();
            $table->text('insight')->nullable();        // encrypted
            $table->text('prayer')->nullable();         // encrypted
            $table->text('reflection')->nullable();     // encrypted
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
