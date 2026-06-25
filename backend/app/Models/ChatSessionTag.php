<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatSessionTag extends Model
{
    public $timestamps = false;

    protected $fillable = ['chat_session_id', 'tag', 'auto'];

    protected $casts = [
        'auto'       => 'boolean',
        'created_at' => 'datetime',
    ];

    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }
}
