<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdImpression extends Model
{
    // Only shown_at — no updated_at column.
    const CREATED_AT = 'shown_at';
    const UPDATED_AT = null;

    protected $fillable = [
        'ad_id',
        'ad_slide_id',
        'session_token',
        'location',
        'duration_ms',
        'clicked',
        'language',
        'mood',
        'shown_at',
    ];

    protected $casts = [
        'clicked'  => 'boolean',
        'shown_at' => 'datetime',
    ];

    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }

    public function slide(): BelongsTo
    {
        return $this->belongsTo(AdSlide::class, 'ad_slide_id');
    }
}
