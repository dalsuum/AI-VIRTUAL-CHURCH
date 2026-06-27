<?php

namespace App\Domains\Church\Policies;

use App\Domains\Church\Models\Church;
use App\Enums\ChurchRole;
use App\Models\User;

/**
 * Church-scoped authorization. Each ability declares the MINIMUM role it requires and
 * defers the comparison to ChurchRole::atLeast — the enum owns the hierarchy, so no
 * policy hard-codes numeric levels or the role order. Adding a tier changes only the
 * enum. Capabilities map to the platform's church responsibilities (moderation,
 * member management, broadcasting, guest invites) and will be reused by worship/study/
 * prayer features as they land.
 */
class ChurchPolicy
{
    public function view(User $user, Church $church): bool
    {
        return $user->hasChurchRole($church->id, ChurchRole::MEMBER);
    }

    /** Create worship / Bible-study / prayer sessions for the church. */
    public function createSession(User $user, Church $church): bool
    {
        return $user->hasChurchRole($church->id, ChurchRole::LEADER);
    }

    /** Moderate discussions, approve prayer requests. */
    public function moderate(User $user, Church $church): bool
    {
        return $user->hasChurchRole($church->id, ChurchRole::DEACON);
    }

    /** Manage memberships (roles, removals) and broadcast announcements. */
    public function manage(User $user, Church $church): bool
    {
        return $user->hasChurchRole($church->id, ChurchRole::ELDER);
    }
}
