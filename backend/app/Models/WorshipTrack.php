<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A worship track in the recommendation catalog (AI Worship Radio).
 *
 * Holds only metadata + official streaming links — NEVER hosted copyrighted
 * audio (see worship_tracks migration). `themes`/`moods`/`scriptures` are tag
 * arrays consumed by MusicRecommendationService.
 */
class WorshipTrack extends Model
{
    protected $fillable = [
        'title', 'artist', 'language', 'genre',
        'themes', 'moods', 'scriptures', 'duration',
        'youtube_url', 'spotify_url', 'apple_music_url', 'cover_image',
        'lyrics_available', 'copyright_status', 'popularity', 'active',
    ];

    protected $casts = [
        'themes'           => 'array',
        'moods'            => 'array',
        'scriptures'       => 'array',
        'duration'         => 'integer',
        'popularity'       => 'integer',
        'lyrics_available' => 'boolean',
        'active'           => 'boolean',
    ];
}
