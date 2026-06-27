<?php

namespace App\Enums;

/**
 * Audience scope for any owner-owned resource (profile, activity, presence, feed,
 * prayer request, …). Resolved in exactly one place — App\Domains\Accounts\Services\
 * PrivacyGate — so no controller or policy re-implements visibility rules.
 *
 * Ordered least → most public. New tiers (e.g. small_group) slot in here and the
 * gate gains one branch; callers never change.
 */
enum Visibility: string
{
    case PRIVATE = 'private';   // owner only
    case FRIENDS = 'friends';   // accepted friends
    case CHURCH  = 'church';    // fellow active members of a shared church (or friends)
    case PUBLIC  = 'public';    // anyone

    /** Higher = more people can see. Used when comparing/clamping default visibility. */
    public function rank(): int
    {
        return match ($this) {
            self::PRIVATE => 0,
            self::FRIENDS => 1,
            self::CHURCH  => 2,
            self::PUBLIC  => 3,
        };
    }
}
