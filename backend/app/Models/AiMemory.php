<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Owner-scoped memory entry. Every read MUST filter on module + owner (user_id XOR
 * guest_session_id) — no cross-session or cross-user recall. Only moderator
 * summaries enter long-term ('summary'/'semantic') memory.
 */
class AiMemory extends Model
{
    public const KINDS = ['window', 'summary', 'semantic'];

    public $timestamps = false; // created_at only

    protected $fillable = [
        'module', 'session_id', 'user_id', 'guest_session_id', 'kind', 'content', 'embedding',
    ];

    protected $casts = [
        'embedding'  => 'array',
        'created_at' => 'datetime',
    ];

    /** Recall scoped to a single owner — never crosses users or guests. */
    public function scopeForOwner($query, string $module, ?int $userId, ?string $guestId)
    {
        $query->where('module', $module);

        if ($userId !== null) {
            return $query->where('user_id', $userId);
        }

        return $query->where('guest_session_id', $guestId);
    }
}
