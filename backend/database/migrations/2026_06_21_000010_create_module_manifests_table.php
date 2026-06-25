<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI Core plugin registry. Each ministry module (bible_study, ai_sermon,
        // prayer_room, …) is one manifest row. Adding a module = inserting a
        // validated manifest + its assets — no Core code change. `config` holds
        // server-only orchestration knobs and is never serialized to clients.
        Schema::create('module_manifests', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();                 // e.g. bible_study
            $table->string('display_name', 120);
            $table->boolean('enabled')->default(false);
            // Activation is gated on validation; invalid configs are rejected,
            // never auto-recovered (fail-closed).
            $table->enum('status', ['draft', 'active', 'invalid'])->default('draft');
            $table->json('languages')->nullable();               // allowed lang codes
            $table->unsignedTinyInteger('default_agent_count')->default(4);
            $table->unsignedTinyInteger('min_agent_count')->default(2);
            $table->unsignedTinyInteger('max_agent_count')->default(7);
            $table->enum('memory_strategy', ['none', 'window', 'summary', 'semantic'])
                  ->default('window');
            $table->json('rag_sources')->nullable();             // ordered source keys
            $table->json('config')->nullable();                  // 🔒 server-only knobs
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_manifests');
    }
};
