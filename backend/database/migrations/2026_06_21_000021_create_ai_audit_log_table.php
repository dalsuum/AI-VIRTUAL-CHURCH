<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only audit trail for admin config changes: persona edits, prompt
        // edits, provider changes, tool registration, manifest activation. The
        // before/after diffs are written with secrets REDACTED (never store raw API
        // keys or full server-only prompt bodies beyond the editor's own scope).
        // No update/delete in the application layer.
        Schema::create('ai_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);                // e.g. provider.key.updated
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('before')->nullable();          // 🔒 redacted diff
            $table->json('after')->nullable();           // 🔒 redacted diff
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['actor_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_audit_log');
    }
};
