<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Learner-facing, AI-generated vocabulary entries — a CACHE, not authored content.
 * Each row renders one curated `vocabularies` concept into one language (payload JSON:
 * pronunciation, meaning, examples, synonyms, …). Additive and parallel to the
 * existing Zolai/Chin reference dictionary, which is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vocab_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vocabulary_id')->constrained('vocabularies')->cascadeOnDelete();
            $table->string('language', 12);
            $table->string('word')->nullable();          // headword in the target language
            $table->string('difficulty', 16)->nullable();
            $table->json('payload')->nullable();         // full learner entry (null while generating)
            $table->timestamps();

            $table->unique(['vocabulary_id', 'language']); // one cached entry per concept+language
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vocab_entries');
    }
};
