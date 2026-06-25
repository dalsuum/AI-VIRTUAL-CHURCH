<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The hot path. Deliberately narrow with a covering (session_id, turn) index
        // so rendering a session is a single index range scan with no joins —
        // supports millions of rows. Persisted turns are the source of truth for SSE
        // replay; the live token stream is ephemeral.
        //
        // Only `role = 'user'` rows carry worshipper input; user text is conversation
        // DATA and never reaches system/persona/tool/provider config.
        Schema::create('study_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('study_sessions')->cascadeOnDelete();
            $table->unsignedInteger('turn');
            $table->enum('role', ['user', 'moderator', 'pastor', 'synthesis', 'system']);
            $table->foreignId('persona_id')->nullable()
                  ->constrained('ai_personas')->nullOnDelete();
            $table->mediumText('content');
            $table->json('scripture_refs')->nullable();          // detected refs for cards
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->boolean('safety_flag')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['session_id', 'turn']);               // covering render query
            $table->index(['session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_messages');
    }
};
