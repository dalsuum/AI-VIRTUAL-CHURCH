<?php

namespace App\Policies;

use App\Models\StudySession;
use App\Models\User;

/**
 * Owner-scoped authorization. A session belongs to the user who created it (guests
 * are users with @guest.local accounts), so all access is checked against user_id —
 * never an internal id alone. No cross-user access is ever permitted.
 */
class StudySessionPolicy
{
    public function view(User $user, StudySession $session): bool
    {
        return $this->owns($user, $session);
    }

    public function update(User $user, StudySession $session): bool
    {
        return $this->owns($user, $session);
    }

    public function stream(User $user, StudySession $session): bool
    {
        return $this->owns($user, $session);
    }

    private function owns(User $user, StudySession $session): bool
    {
        return $session->user_id !== null && (int) $session->user_id === (int) $user->id;
    }
}
