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
    /** See the church's public face — profile and the ministry-group catalog.
     *  Any active membership, GUESTS INCLUDED: someone who entered through a
     *  group invitation link has been invited into the community and may see
     *  what it is (v1.3 acceptance finding; owner decision 2026-07-10). */
    public function view(User $user, Church $church): bool
    {
        return $user->hasChurchRole($church->id, ChurchRole::GUEST);
    }

    /** See who belongs — the member directory/roster and the church-wide
     *  activity feed. Member names stay member-visible: guests hold
     *  participation, not pastoral recognition. */
    public function viewDirectory(User $user, Church $church): bool
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
