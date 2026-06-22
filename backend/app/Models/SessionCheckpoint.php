<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rehydratable state snapshot taken at a node — what "resume" reconstructs across all
 * modules. `state_blob` is encrypted at rest and stored as JSON via the accessor.
 */
class SessionCheckpoint extends Model
{
    public $timestamps = false;

    protected $fillable = ['session_id', 'node_id', 'state_blob'];

    protected $casts = [
        'state_blob' => 'encrypted:array',
        'created_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }
}
