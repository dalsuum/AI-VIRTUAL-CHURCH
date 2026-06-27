<?php

namespace App\Domains\Bible\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadingPlanDay extends Model
{
    protected $fillable = ['reading_plan_id', 'sequence', 'slug', 'title', 'passages'];

    protected $casts = ['passages' => 'array'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ReadingPlan::class, 'reading_plan_id');
    }
}
