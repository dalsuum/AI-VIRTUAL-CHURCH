<?php

namespace App\Domains\Friends\Models;

use App\Enums\FriendStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Canonical row for an unordered user pair (user_id = min, friend_id = max).
 * Read helpers here are the single source of truth for "are these two friends?"
 * and "is either blocking the other?" — PrivacyGate and policies call them so the
 * canonical-pair ordering is never reimplemented. FriendshipService owns all writes.
 *
 * Soft-deleted = "no relationship" (NONE). A soft-deleted row is reused (restored)
 * when the pair re-engages, so the unique (user_id, friend_id) constraint holds and
 * audit history survives. Read helpers ignore trashed rows; the service resolves
 * the row withTrashed so it can restore it.
 */
class Friendship extends Model
{
    use SoftDeletes;

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

    /** The live canonical row for a pair (ignores trashed), or null. */
    public static function between(int $a, int $b): ?self
    {
        return $a === $b ? null : static::query()->forPair($a, $b)->first();
    }

    public static function areFriends(int $a, int $b): bool
    {
        return static::between($a, $b)?->status === FriendStatus::ACCEPTED;
    }

    /** True if a live block exists in either direction between the pair. */
    public static function blockExistsBetween(int $a, int $b): bool
    {
        return static::between($a, $b)?->status === FriendStatus::BLOCKED;
    }

    /** Has $userId favorited the other side of this pair? (favorite is one-sided.) */
    public function isFavoritedBy(int $userId): bool
    {
        return in_array($userId, $this->favorited_by ?? [], true);
    }
}
