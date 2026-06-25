<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spiritual-profile preferences surfaced on the account page and used to
        // personalize sessions + AI memory recall. All nullable/optional.
        Schema::table('users', function (Blueprint $table) {
            $table->string('fav_language', 12)->nullable();
            $table->string('fav_bible_version', 12)->nullable();
            $table->string('fav_worship_language', 12)->nullable();
            $table->string('fav_pastor', 80)->nullable();
            $table->string('fav_worship_style', 40)->nullable();
            $table->json('fav_books')->nullable();
            $table->json('fav_topics')->nullable();
            $table->text('spiritual_goals')->nullable();
            // When false, new sessions must NOT reference prior history (spec: opt-in).
            $table->boolean('ai_memory_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'fav_language', 'fav_bible_version', 'fav_worship_language',
                'fav_pastor', 'fav_worship_style', 'fav_books', 'fav_topics',
                'spiritual_goals', 'ai_memory_enabled',
            ]);
        });
    }
};
