<?php

namespace App\Domains\Friends\Policies;

use App\Domains\Accounts\Services\PrivacyGate;
use App\Models\User;

/**
 * Authorization boundary for initiating contact with another member. Whether a user
 * MAY reach another (block / friend-only / self) is a privacy decision, so the policy
 * delegates to PrivacyGate — the single visibility authority — rather than re-deriving
 * the rule. Transition legality (is there a pending request? are you the invitee?) is a
 * business invariant owned by FriendshipService, not an authorization concern.
 */
class FriendshipPolicy
{
    public function __construct(private readonly PrivacyGate $privacy)
    {
    }

    /** May $actor send a friend request / invitation to $target? */
    public function interact(User $actor, User $target): bool
    {
        return $this->privacy->canInteract($actor, $target);
    }
}
