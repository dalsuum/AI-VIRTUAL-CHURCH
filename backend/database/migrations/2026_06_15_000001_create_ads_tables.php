<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ads', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title', 150);
            $table->enum('status', ['draft', 'active', 'paused'])->default('draft');
            $table->enum('type', ['slideshow', 'html'])->default('slideshow');
            $table->json('locations');                          // array of 'start','between','end'
            $table->string('target_language', 5)->nullable();  // null = all
            $table->json('target_moods')->nullable();           // [] = all
            $table->char('currency', 3)->default('USD');
            $table->decimal('price_per_impression', 10, 4)->default(0);
            $table->decimal('price_per_click', 10, 4)->default(0);
            $table->unsignedInteger('slide_duration')->default(5); // seconds per slide
            $table->text('html_content')->nullable();
            $table->timestamps();
        });

        Schema::create('ad_slides', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('ad_id')->constrained('ads')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->enum('type', ['image', 'html'])->default('image');
            $table->string('image_path', 500)->nullable();
            $table->text('html_content')->nullable();
            $table->string('link_url', 500)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable(); // overrides ad default
            $table->timestamps();
        });

        Schema::create('ad_impressions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('ad_id')->constrained('ads')->cascadeOnDelete();
            $table->foreignId('ad_slide_id')->nullable()->constrained('ad_slides')->cascadeOnDelete();
            $table->string('session_token', 64)->nullable();
            $table->enum('location', ['start', 'between', 'end']);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->boolean('clicked')->default(false);
            $table->string('language', 5)->nullable();
            $table->string('mood', 80)->nullable();
            $table->timestamp('shown_at')->useCurrent();
            // No timestamps() — only shown_at
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_impressions');
        Schema::dropIfExists('ad_slides');
        Schema::dropIfExists('ads');
    }
};
