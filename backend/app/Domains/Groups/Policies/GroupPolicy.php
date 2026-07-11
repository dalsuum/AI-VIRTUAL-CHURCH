<?php

namespace App\Domains\Groups\Policies;

use App\Domains\Church\Models\Church;
use App\Domains\Groups\Models\Group;
use App\Enums\ChurchRole;
use App\Enums\GroupRole;
use App\Models\User;

/**
 * Group-scoped authorization. Two ladders meet here: a group's own leader manages
 * their group, and church officers oversee ALL groups in their church without
 * needing a membership row in each. Thresholds are owned by the enums
 * (GroupRole::atLeast / ChurchRole::atLeast), never hard-coded.
 */
class GroupPolicy
{
    /** Groups are visible church-wide and to their own members (a link-joined guest
     *  holds only ChurchRole::GUEST but must still see the group they joined). */
    public function view(User $user, Group $group): bool
    {
        return $user->hasChurchRole($group->church_id, ChurchRole::MEMBER)
            || $user->hasGroupRole($group->id, GroupRole::MEMBER);
    }

    /** Groups are SELF-ORGANIZATION (owner decision 2026-07-11): ANY member may
     *  form one — a couple's two-person group, a few friends' study circle — and
     *  the creator becomes its leader (ChurchController::storeGroup). Guests still
     *  cannot: full participation follows membership, a pastoral decision. */
    public function create(User $user, Church $church): bool
    {
        return $user->hasChurchRole($church->id, ChurchRole::MEMBER);
    }

    /** Group settings and memberships: the group's own leader, or church elders and above. */
    public function manage(User $user, Group $group): bool
    {
        return $user->hasGroupRole($group->id, GroupRole::LEADER)
            || $user->hasChurchRole($group->church_id, ChurchRole::ELDER);
    }

    /** Deleting a group is church governance — above a group leader's authority. */
    public function delete(User $user, Group $group): bool
    {
        return $user->hasChurchRole($group->church_id, ChurchRole::ELDER);
    }
}
