<?php

namespace App\Domains\Invitations\Policies;

use App\Domains\Invitations\Models\Invitation;
use App\Models\User;

/**
 * Who MAY attempt an action on an invitation. (Whether the transition is legal from
 * the current state is a business invariant owned by InvitationService.) Only the two
 * parties see it; only the invitee responds; only the inviter cancels.
 */
class InvitationPolicy
{
    public function view(User $user, Invitation $invitation): bool
    {
        return in_array($user->id, [$invitation->inviter_id, $invitation->invitee_id], true);
    }

    public function respond(User $user, Invitation $invitation): bool
    {
        return $user->id === $invitation->invitee_id;
    }

    public function cancel(User $user, Invitation $invitation): bool
    {
        return $user->id === $invitation->inviter_id;
    }
}
