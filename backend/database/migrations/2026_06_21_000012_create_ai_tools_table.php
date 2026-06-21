<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Closed tool registry. A plugin may invoke ONLY tools explicitly
        // registered here and joined to it via manifest_tools (allow-list).
        // `handler_ref` resolves to a code-side handler map — there is no dynamic
        // function execution or arbitrary handler loading from user input.
        Schema::create('ai_tools', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->json('json_schema');                 // OpenAI-format function schema
            $table->string('handler_ref', 120);          // 🔒 code-side handler key
            $table->json('scopes')->nullable();          // capability scopes
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tools');
    }
};
