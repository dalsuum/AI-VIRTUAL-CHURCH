<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Role prompt templates, per module + language. The `body` is admin-editable
        // but SERVER-ONLY — the prompt engine ALWAYS prepends immutable core
        // invariants and wraps untrusted context regardless of body contents, so an
        // edit can never remove the trust fences. One row per (module, language, role).
        Schema::create('ai_prompt_templates', function (Blueprint $table) {
            $table->id();
            $table->string('module', 64);
            $table->string('language', 12);
            $table->enum('role', ['frame', 'pastor', 'synthesis', 'summary', 'system']);
            $table->text('body');                         // 🔒 server-only
            $table->decimal('temperature', 3, 2)->default(0.70);
            $table->unsignedInteger('max_tokens')->default(800);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['module', 'language', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompt_templates');
    }
};
