<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MusicSessionMeta extends Model
{
    protected $table = 'music_sessions';

    protected $fillable = [
        'chat_session_id', 'playlist', 'songs_played', 'liked', 'skipped', 'duration',
    ];

    protected $casts = [
        'playlist'     => 'array',
        'songs_played' => 'array',
        'liked'        => 'array',
        'skipped'      => 'array',
        'duration'     => 'integer',
    ];

    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }
}
