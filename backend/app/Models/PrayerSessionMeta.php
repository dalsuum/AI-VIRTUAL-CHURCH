<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrayerSessionMeta extends Model
{
    protected $table = 'prayer_sessions';

    protected $fillable = [
        'chat_session_id', 'prayer_topics', 'answered_prayer', 'private',
    ];

    protected $casts = [
        'prayer_topics'   => 'array',
        'answered_prayer' => 'boolean',
        'private'         => 'boolean',
    ];

    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }
}
