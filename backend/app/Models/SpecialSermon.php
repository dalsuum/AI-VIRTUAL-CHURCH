<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hand-authored sermon attached to a special Sunday, for one service language.
 * Served verbatim (spoken as-is) when the day's sermon mode for that language is
 * 'manual'. See SpecialSunday::resolveContent().
 */
class SpecialSermon extends Model
{
    protected $fillable = [
        'special_sunday_id', 'language', 'title', 'body',
        'mood', 'region', 'priority', 'active',
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
