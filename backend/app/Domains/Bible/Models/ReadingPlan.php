<?php

namespace App\Domains\Bible\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReadingPlan extends Model
{
    protected $fillable = ['slug', 'title', 'description', 'language', 'day_count'];

    public function days(): HasMany
    {
        return $this->hasMany(ReadingPlanDay::class)->orderBy('sequence');
    }

    public function dayAt(int $sequence): ?ReadingPlanDay
    {
        return $this->days()->where('sequence', $sequence)->first();
    }
}
