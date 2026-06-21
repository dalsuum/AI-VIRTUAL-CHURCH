<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Generic, module-keyed AI personas (the fictional pastors + moderators).
        // `display_name`/`avatar_ref` are PUBLIC. `tradition_tag` (the real-world
        // inspiration used only to steer tone) and `system_prompt` are SERVER-ONLY
        // and must never be serialized to any public API or SSE event.
        //
        // `weight` (0–100) drives weighted participation: probability of inclusion
        // in a session AND per-turn token budget. `module = '*'` marks a persona
        // reusable across all ministry modules.
        Schema::create('ai_personas', function (Blueprint $table) {
            $table->id();
            $table->string('module', 64)->index();       // FK→module_manifests.key, or '*'
            $table->string('language', 12);
            $table->string('display_name', 120);         // public
            $table->string('avatar_ref', 255)->nullable(); // public
            $table->string('tradition_tag', 120)->nullable(); // 🔒 server-only inspiration
            $table->text('system_prompt');               // 🔒 server-only
            $table->unsignedTinyInteger('weight')->default(50);
            $table->boolean('is_moderator')->default(false);
            $table->foreignId('provider_profile_id')->nullable()
                  ->constrained('ai_provider_profiles')->nullOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['module', 'language', 'enabled']);
            $table->index(['module', 'language', 'is_moderator']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_personas');
    }
};
