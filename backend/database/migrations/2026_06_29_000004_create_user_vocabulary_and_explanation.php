<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Phase C: the ONLY per-user vocabulary state — favorites and viewed history — plus a
 * cached AI "Explain" column. Favorites/history are per (user, concept, kind); the
 * explanation is cached per (concept, language) on vocab_entries, like the entry itself
 * (no permanent per-user storage of AI output — keeps cost/storage low).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_vocabulary', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vocabulary_id')->constrained('vocabularies')->cascadeOnDelete();
            $table->string('kind', 12);                     // 'favorite' | 'viewed'
            $table->timestamps();                           // updated_at = recency for 'viewed'

            $table->unique(['user_id', 'vocabulary_id', 'kind']);
            $table->index(['user_id', 'kind', 'updated_at']); // list newest-first per kind
        });

        Schema::table('vocab_entries', function (Blueprint $table) {
            $table->text('explanation')->nullable()->after('payload'); // cached AI Explain
        });
    }

    public function down(): void
    {
        Schema::table('vocab_entries', fn (Blueprint $t) => $t->dropColumn('explanation'));
        Schema::dropIfExists('user_vocabulary');
    }
};
