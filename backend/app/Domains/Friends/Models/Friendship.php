<?php

namespace App\Domains\Friends\Models;

use App\Enums\FriendStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Canonical row for an unordered user pair (user_id = min, friend_id = max).
 * Read helpers here are the single source of truth for "are these two friends?"
 * and "is either blocking the other?" — PrivacyGate and policies call them so the
 * canonical-pair ordering is never reimplemented. FriendshipService owns writes.
 */
class Friendship extends Model
{
    protected $fillable = [
        'user_id', 'friend_id', 'status', 'requested_by', 'blocked_by',
        'favorited_by', 'responded_at',
    ];

    protected $casts = [
        'status'       => FriendStatus::class,
        'favorited_by' => 'array',
        'responded_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** Normalize any two ids to the stored [min, max] pair order. */
    public static function orderedPair(int $a, int $b): array
    {
        return $a <= $b ? [$a, $b] : [$b, $a];
    }

    public function scopeForPair(Builder $q, int $a, int $b): Builder
    {
        [$lo, $hi] = self::orderedPair($a, $b);

        return $q->where('user_id', $lo)->where('friend_id', $hi);
    }

    /** The single canonical row for a pair, or null. */
    public static function between(int $a, int $b): ?self
    {
        return $a === $b ? null : static::query()->forPair($a, $b)->first();
    }

    public static function areFriends(int $a, int $b): bool
    {
        return (bool) (static::between($a, $b)?->status === FriendStatus::ACCEPTED);
    }

    /** True if a block exists in either direction between the pair. */
    public static function blockExistsBetween(int $a, int $b): bool
    {
        return static::between($a, $b)?->status === FriendStatus::BLOCKED;
    }
}
