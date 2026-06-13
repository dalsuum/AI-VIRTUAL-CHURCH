<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One reusable AI-composed worship song in the language-and-mood-keyed pool. Populated by the
 * worker after each fresh Suno generation (deduped by provider_ref) and drawn from
 * by DispatchServiceJob when a worshipper is new to a given mood.
 */
class MusicTrack extends Model
{
    protected $fillable = ['mood', 'language', 'provider_ref', 'storage_key', 'title', 'lyrics', 'source'];
}
