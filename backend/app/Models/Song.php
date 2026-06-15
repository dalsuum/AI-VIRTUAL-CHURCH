<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A worship song in the user-managed library (admin Lyrics tab → public song panel).
 * `lyrics` is ChordPro-flavoured text supporting inline chords like "[G]Amazing".
 */
class Song extends Model
{
    protected $fillable = ['language', 'title', 'artist', 'category', 'lyrics', 'has_chords', 'source', 'url'];

    protected $casts = [
        'has_chords' => 'boolean',
    ];
}
