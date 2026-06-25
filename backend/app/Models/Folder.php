<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user-owned sidebar folder for grouping history sessions. Always owner-scoped
 * (forUser) — a folder is never visible to another user.
 */
class Folder extends Model
{
    protected $fillable = ['user_id', 'name', 'color', 'position'];

    protected $casts = ['position' => 'integer'];

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ChatSession::class, 'folder_id');
    }
}
