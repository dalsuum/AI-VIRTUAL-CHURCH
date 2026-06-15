<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hymns', function (Blueprint $table) {
            $table->id();
            $table->enum('language', ['en', 'my', 'td']);
            $table->string('slug')->nullable()->comment('For file-based caching of audio renders');
            $table->string('title');
            $table->string('title_en')->nullable()->comment('English title for mood tagging');
            $table->text('lyrics');
            $table->json('moods')->nullable();
            $table->string('youtube_id')->nullable()->comment('For Tedim hymn video embeds');
            $table->timestamps();

            $table->unique(['language', 'slug']);
            $table->index('language');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hymns');
    }
};