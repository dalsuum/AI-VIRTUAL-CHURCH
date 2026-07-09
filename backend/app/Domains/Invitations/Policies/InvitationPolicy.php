<?php

namespace App\Domains\Invitations\Policies;

use App\Domains\Invitations\Models\Invitation;
use App\Enums\InvitationKind;
use App\Models\User;

/**
 * Who MAY attempt an action on an invitation. (Whether the transition is legal from
 * the current state is a business invariant owned by InvitationService.) Only the two
 * parties see it; only the invitee responds; only the inviter cancels — except LINK
 * invitations, where anyone who can manage the target group shares revocation
 * authority with the creator (a rogue link must not outlive its leader).
 */
class InvitationPolicy
{
    public function view(User $user, Invitation $invitation): bool
    {
        return in_array($user->id, [$invitation->inviter_id, $invitation->invitee_id], true)
            // Links and requests concern the target group; its managers see them.
            || ($invitation->kind !== InvitationKind::DIRECT
                && $invitation->invitable !== null
                && $user->can('manage', $invitation->invitable));
    }

    public function respond(User $user, Invitation $invitation): bool
    {
        return $user->id === $invitation->invitee_id
            // A REQUEST has no invitee — the target group's managers approve/deny.
            || ($invitation->kind === InvitationKind::REQUEST
                && $invitation->invitable !== null
                && $user->can('manage', $invitation->invitable));
    }

    public function cancel(User $user, Invitation $invitation): bool
    {
        return $user->id === $invitation->inviter_id
            || ($invitation->kind === InvitationKind::LINK
                && $invitation->invitable !== null
                && $user->can('manage', $invitation->invitable));
    }
}
