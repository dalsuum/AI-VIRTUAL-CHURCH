<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Auto-generated (worker) + user-edited tags from a fixed spiritual vocabulary
        // (Faith, Hope, Anxiety, Healing, Marriage, …). Powers filtering + the Journey
        // "most discussed topics" stat.
        Schema::create('chat_session_tags', function (Blueprint $table) {
            $table->id();
            $table->uuid('chat_session_id');
            $table->foreign('chat_session_id')->references('id')->on('chat_sessions')->cascadeOnDelete();
            $table->string('tag', 40);
            $table->boolean('auto')->default(true);                // worker vs user-added
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['chat_session_id', 'tag']);
            $table->index('tag');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_session_tags');
    }
};
