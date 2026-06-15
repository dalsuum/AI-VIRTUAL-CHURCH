<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User-managed worship song library, edited from the admin Lyrics tab and shown
 * on the public song panel. `lyrics` holds ChordPro-flavoured text so chords can
 * be entered inline (e.g. "[G]Amazing [C]grace"); `has_chords` flags sheets that
 * carry chord markers for the front-end renderer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('songs', function (Blueprint $table) {
            $table->id();
            $table->enum('language', ['my', 'td'])->default('my')->index();
            $table->string('title');
            $table->string('artist')->nullable();
            $table->string('category')->nullable();
            $table->text('lyrics');
            $table->boolean('has_chords')->default(false);
            $table->string('source')->default('manual');
            $table->timestamps();

            $table->index(['language', 'title']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('songs');
    }
};
