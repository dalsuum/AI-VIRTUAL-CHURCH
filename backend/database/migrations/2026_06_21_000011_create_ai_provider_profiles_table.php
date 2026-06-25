<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI provider connection profiles (OpenRouter / Ollama / RunPod / LM Studio /
        // generic OpenAI-compatible). Credentials are NEVER stored in plaintext:
        // `key_ciphertext` uses Laravel's encrypted cast, or `key_ref` names an
        // env/secret resolved server-side at dispatch time. API resources expose
        // only `key_set` (bool) — never the ciphertext or ref.
        Schema::create('ai_provider_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->enum('type', [
                'openrouter', 'ollama', 'runpod', 'lmstudio', 'openai_compatible',
            ]);
            $table->string('base_url', 255)->nullable();
            $table->string('model', 160)->nullable();
            $table->text('key_ciphertext')->nullable();          // 🔒 encrypted at rest
            $table->string('key_ref', 120)->nullable();          // 🔒 alt: env/secret name
            $table->json('params')->nullable();                  // temp/max_tokens defaults
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_profiles');
    }
};
