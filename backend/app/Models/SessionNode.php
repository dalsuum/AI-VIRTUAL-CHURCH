<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A node in a session graph — the durable truth for session state (see
 * docs/session-state-store.md). A message is just type=message. `content` is encrypted
 * at rest. Written only through SessionStateStore; never persisted ad hoc by modules.
 */
class SessionNode extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'id', 'session_id', 'parent_node_id', 'branch_id', 'seq',
        'type', 'sender', 'content', 'metadata', 'token_usage',
    ];

    protected $casts = [
        'content'     => 'encrypted',
        'metadata'    => 'array',
        'seq'         => 'integer',
        'token_usage' => 'integer',
        'created_at'  => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }
}
