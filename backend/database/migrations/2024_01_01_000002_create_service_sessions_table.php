<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_token', 64)->unique();
            $table->enum('status', ['initializing', 'active', 'completed', 'abandoned'])
                  ->default('initializing');

            // Resolved once at session start from the user's preference, so a single
            // service uses one consistent media source even if the user later changes it.
            $table->enum('music_source', ['suno', 'youtube'])->default('suno');

            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_sessions');
    }
};
