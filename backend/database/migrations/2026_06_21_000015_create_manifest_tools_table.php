<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-plugin tool allow-list. A module may invoke a registered tool ONLY if
        // an enabled row joins them here. This is the authorization edge for tool
        // use — the orchestrator never trusts a model's request for an un-joined tool.
        Schema::create('manifest_tools', function (Blueprint $table) {
            $table->id();
            $table->string('module', 64);                // FK→module_manifests.key
            $table->foreignId('tool_id')->constrained('ai_tools')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['module', 'tool_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manifest_tools');
    }
};
