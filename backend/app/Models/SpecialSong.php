<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A specific song attached to a special Sunday, for one service language. Served
 * for the worship/closing segments when the day's music mode for that language is
 * 'manual'. Four source kinds, distinguished by `source_type`:
 *   youtube → source_ref = video id/URL
 *   hymn    → source_ref = a Song library id (lyrics + audio reused)
 *   audio   → source_ref = a direct hosted audio URL
 *   suno    → source_ref = a composition prompt (fresh track at service time)
 */
class SpecialSong extends Model
{
    public const SOURCE_TYPES = ['youtube', 'hymn', 'audio', 'suno'];

    protected $fillable = [
        'special_sunday_id', 'language', 'title', 'source_type', 'source_ref',
        'lyrics', 'mood', 'region', 'priority', 'active',
    ];

    protected $casts = [
        'priority' => 'integer',
        'active'   => 'boolean',
    ];

    public function specialSunday(): BelongsTo
    {
        return $this->belongsTo(SpecialSunday::class);
    }
}
