<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global, admin-editable application settings as simple key/value rows.
        // The first inhabitant is `narration_mode` (off | browser | openai), which
        // chooses how spoken segments are read aloud across all services.
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
