<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The vocabulary reference started Zolai-only (plus Burmese/Hebrew/English).
 * As the app gained more Chin/Zo languages, the public #vocabulary page now
 * offers a language dropdown (default Zolai) so a worshipper can read each word
 * in their own ethnic tongue. Each language gets its own nullable column so the
 * existing seeded Zolai data is untouched and rows can be filled in over time.
 */
return new class extends Migration
{
    /** New ethnic-language gloss columns, code => true. Mirrors the Bible reader set. */
    private array $columns = ['falam', 'hakha', 'matu', 'mizo', 'paite', 'sizang'];

    public function up(): void
    {
        Schema::table('vocabularies', function (Blueprint $table) {
            foreach ($this->columns as $col) {
                $table->string($col)->nullable()->after('zolai');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vocabularies', function (Blueprint $table) {
            foreach ($this->columns as $col) {
                $table->dropColumn($col);
            }
        });
    }
};
