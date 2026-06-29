<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks every Knowledge corpus ingestion run so the admin dashboard can show
 * status, progress, errors, and duplicate detection across processes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_ingest_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('collection');                // target corpus name
            $table->string('original_filename');
            $table->string('file_hash', 64)->index();   // SHA-256; used for duplicate detection
            $table->string('idempotency_key', 64)->unique(); // SHA-256(collection|file_hash|chunker|language|embedding_driver|dims)
            $table->bigInteger('file_size')->unsigned(); // bytes
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled'])
                  ->default('pending')
                  ->index();
            $table->unsignedInteger('document_count')->nullable();
            $table->unsignedInteger('chunk_count')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();    // wall-clock ingestion time
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();        // language, source, embedding_model, avg_chunk_size, etc.
            $table->string('storage_path')->nullable();  // temp file location
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_ingest_jobs');
    }
};
