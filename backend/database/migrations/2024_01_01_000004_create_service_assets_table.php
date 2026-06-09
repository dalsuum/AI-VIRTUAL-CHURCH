<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('service_sessions')->cascadeOnDelete();

            $table->enum('segment', [
                'worship', 'opening_prayer', 'scripture', 'sermon',
                'testimony', 'offering', 'closing_hymn', 'benediction',
            ]);

            // 'video'/'audio' = a file in object storage (storage_key holds the key).
            // 'youtube'       = an embedded YouTube clip (provider_ref holds the video id).
            // 'text'          = inline text payload (text_payload holds it).
            $table->enum('asset_type', ['video', 'audio', 'text', 'url', 'youtube']);

            $table->string('storage_key', 512)->nullable();   // S3/OCI key for generated media
            $table->string('provider_ref', 255)->nullable();  // YouTube video id, HeyGen job id, etc.
            $table->longText('text_payload')->nullable();      // sermon/prayer/scripture text

            $table->enum('status', ['queued', 'processing', 'ready', 'failed'])->default('queued');
            $table->timestamp('ready_at')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'segment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_assets');
    }
};
