<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An AI-written Spiritual Journal entry distilled from a session. Append-only and
 * owner-scoped; reflective fields are encrypted at rest. Survives deletion of its
 * source session (chat_session_id nullOnDelete) — the journal is the lasting record.
 */
class JournalEntry extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'chat_session_id', 'status', 'title',
        'scripture_ref', 'insight', 'prayer', 'reflection',
    ];

    protected $casts = [
        'insight'    => 'encrypted',
        'prayer'     => 'encrypted',
        'reflection' => 'encrypted',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
