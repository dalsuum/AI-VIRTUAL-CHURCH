<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data-driven catalog of "special Sundays" (Mother's/Father's/Children's/Youth
 * Day, Palm Sunday, Easter, Pentecost, Reformation, Advent, Thanksgiving …).
 *
 * Dates are NEVER stored — they move every year. Each row carries a `rule_type`
 * + `rule` that App\Models\SpecialSunday resolves to an actual Sunday for any
 * year (nth-weekday-of-month, Western-Easter offset, or fixed civil date, each
 * snapped to the nearest Sunday). The resolver then activates the row during
 * its [Fri 00:00 .. Sun 23:59] window to bias sermon + worship selection.
 *
 * Rows are upserted from config/special_sundays.php by SpecialSundaySeeder, so
 * the catalog stays editable per region without further migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('special_sundays', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();            // stable identifier, matches config
            $table->string('rule_type');                // nth_weekday | easter_offset | fixed
            $table->json('rule');                       // rule parameters (see config)
            $table->json('titles');                     // { en, my, td }
            $table->json('briefs');                     // { en, my, td }
            $table->json('sermon_tags');                // string[] — bias sermon theme
            $table->json('music_moods');                // string[] — bias worship mood
            $table->string('region')->nullable();       // optional scope label (null = global)
            $table->unsignedInteger('priority')->default(50); // higher wins on overlap
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_sundays');
    }
};
