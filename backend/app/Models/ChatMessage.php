<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single turn in a chat-style session (Pastor Chat, etc.). `content` is encrypted
 * at rest — pastoral conversation is sensitive. User text is conversation DATA only.
 */
class ChatMessage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'session_id', 'sender', 'message_type', 'content', 'metadata', 'token_usage',
    ];

    protected $casts = [
        'content'     => 'encrypted',
        'metadata'    => 'array',
        'token_usage' => 'integer',
        'created_at'  => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }
}
