<?php

namespace App\Enums;

/**
 * Contextual role a user holds *within a church* (on church_memberships). This is
 * distinct from the platform-privilege role on the User (admin/moderator/…). A user
 * may be a Pastor in one church and a Member in another, so the role lives on the
 * membership, never on the user.
 *
 * Ordered lowest → highest authority. Policies compare level() rather than hard-coding
 * role strings, so adding a tier doesn't touch authorization call sites.
 */
enum ChurchRole: string
{
    case GUEST   = 'guest';
    case MEMBER  = 'member';
    case LEADER  = 'leader';
    case DEACON  = 'deacon';
    case ELDER   = 'elder';
    case PASTOR  = 'pastor';
    case OWNER   = 'owner';

    public function level(): int
    {
        return match ($this) {
            self::GUEST  => 0,
            self::MEMBER => 1,
            self::LEADER => 2,
            self::DEACON => 3,
            self::ELDER  => 4,
            self::PASTOR => 5,
            self::OWNER  => 6,
        };
    }

    /** True when this role is at least as authoritative as $other. */
    public function atLeast(self $other): bool
    {
        return $this->level() >= $other->level();
    }
}
