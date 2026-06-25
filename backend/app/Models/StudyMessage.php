<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn in a discussion. Only role='user' rows carry worshipper input, which is
 * conversation DATA and never reaches system/persona/tool/provider configuration.
 * Persisted turns are the source of truth for SSE replay; the live stream is
 * ephemeral. Kept narrow to keep the (session_id, turn) render scan cheap at scale.
 */
class StudyMessage extends Model
{
    public const ROLES = ['user', 'moderator', 'pastor', 'synthesis', 'system'];

    public $timestamps = false; // created_at only (set by DB default)

    protected $fillable = [
        'session_id', 'turn', 'role', 'persona_id', 'content',
        'scripture_refs', 'prompt_tokens', 'completion_tokens', 'safety_flag',
    ];

    protected $casts = [
        'turn'              => 'integer',
        'scripture_refs'    => 'array',
        'prompt_tokens'     => 'integer',
        'completion_tokens' => 'integer',
        'safety_flag'       => 'boolean',
        'created_at'        => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(StudySession::class, 'session_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(AiPersona::class, 'persona_id');
    }
}
