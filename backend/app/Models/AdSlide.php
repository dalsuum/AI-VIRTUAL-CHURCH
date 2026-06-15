<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdSlide extends Model
{
    protected $fillable = [
        'ad_id',
        'sort_order',
        'type',
        'image_path',
        'html_content',
        'link_url',
        'duration_seconds',
    ];

    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }
}
