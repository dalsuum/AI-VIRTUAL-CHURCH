<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ad extends Model
{
    protected $fillable = [
        'title',
        'status',
        'type',
        'locations',
        'target_language',
        'target_moods',
        'currency',
        'price_per_impression',
        'price_per_click',
        'slide_duration',
        'html_content',
    ];

    protected $casts = [
        'locations'    => 'array',
        'target_moods' => 'array',
        'price_per_impression' => 'float',
        'price_per_click'      => 'float',
    ];

    public function slides(): HasMany
    {
        return $this->hasMany(AdSlide::class)->orderBy('sort_order');
    }

    public function impressions(): HasMany
    {
        return $this->hasMany(AdImpression::class);
    }
}
