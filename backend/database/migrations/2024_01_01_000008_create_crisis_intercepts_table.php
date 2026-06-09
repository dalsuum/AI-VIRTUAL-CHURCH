<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Safety audit trail. Deliberately stores a hash, not the raw text, so no
        // sensitive disclosure is persisted. No LLM ever touches an intercepted intake.
        Schema::create('crisis_intercepts', function (Blueprint $table) {
            $table->id();
            $table->string('session_hash', 64);
            $table->string('trigger_keyword', 100);
            $table->string('resource_served', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crisis_intercepts');
    }
};
