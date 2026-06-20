<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User-managed Zolai ↔ Burmese ↔ English vocabulary reference, edited from the
 * admin Vocabulary tab and shown on the public #vocabulary page. Seeded from the
 * original hand-curated list (see VocabularySeeder); the DB is the source of
 * truth thereafter. `burmese` and `notes` are optional; `source` defaults to
 * 'manual' so admin-added rows are distinguishable from the seeded reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vocabularies', function (Blueprint $table) {
            $table->id();
            $table->string('zolai');
            $table->string('burmese')->nullable();
            $table->string('english');
            $table->string('category')->nullable()->index();
            $table->string('notes', 500)->nullable();
            $table->string('source')->default('manual');
            $table->timestamps();

            $table->index(['category', 'zolai']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vocabularies');
    }
};
