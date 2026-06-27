<?php

namespace App\Domains\Bible\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadingStreak extends Model
{
    protected $fillable = ['user_id', 'current_streak', 'longest_streak', 'last_read_on'];

    protected $casts = [
        'current_streak' => 'integer',
        'longest_streak' => 'integer',
        'last_read_on'   => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
