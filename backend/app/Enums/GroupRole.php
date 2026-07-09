<?php

namespace App\Enums;

/**
 * Contextual role a user holds *within a group* (on group_memberships), distinct from
 * both ChurchRole and the platform role. Ministry titles ("worship leader", "Bible
 * study leader", "choir leader") are LEADER on that specific group — never new
 * ChurchRole cases — so the church hierarchy stays stable as ministries are added.
 *
 * Ordered lowest → highest authority, same contract as ChurchRole: policies compare
 * level() via atLeast() rather than hard-coding role strings.
 */
enum GroupRole: string
{
    case MEMBER = 'member';
    case LEADER = 'leader';

    public function level(): int
    {
        return match ($this) {
            self::MEMBER => 0,
            self::LEADER => 1,
        };
    }

    /** True when this role is at least as authoritative as $other. */
    public function atLeast(self $other): bool
    {
        return $this->level() >= $other->level();
    }
}
