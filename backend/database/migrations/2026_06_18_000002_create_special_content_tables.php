<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Curated content libraries attached to a special Sunday: hand-authored sermons
 * and specific songs, per service language. When an observance's per-language
 * "mode" is flipped to `manual` (see content_modes on special_sundays), the
 * worker serves the highest-priority active entry here instead of the AI/bias
 * selection — otherwise these rows lie dormant and the AI runs as normal.
 *
 * Both tables are tagged by mood/priority/region so several entries can coexist
 * for one day+language and the best match wins (mood first, then priority).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('special_sermons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('special_sunday_id')->constrained()->cascadeOnDelete();
            $table->string('language', 2);            // en | my | td
            $table->string('title');
            $table->longText('body');                 // the full sermon prose (spoken as-is)
            $table->string('mood')->nullable();       // optional mood tag for tie-breaking
            $table->string('region')->nullable();
            $table->unsignedInteger('priority')->default(50);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['special_sunday_id', 'language', 'active']);
        });

        Schema::create('special_songs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('special_sunday_id')->constrained()->cascadeOnDelete();
            $table->string('language', 2);            // en | my | td
            $table->string('title');
            // youtube  -> source_ref = video id or URL
            // hymn     -> source_ref = songs.id / hymn library id
            // audio    -> source_ref = direct hosted audio URL (mp3)
            // suno     -> source_ref = a composition prompt
            $table->string('source_type');            // youtube | hymn | audio | suno
            $table->text('source_ref');
            $table->longText('lyrics')->nullable();    // optional on-screen lyrics
            $table->string('mood')->nullable();
            $table->string('region')->nullable();
            $table->unsignedInteger('priority')->default(50);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['special_sunday_id', 'language', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_songs');
        Schema::dropIfExists('special_sermons');
    }
};
