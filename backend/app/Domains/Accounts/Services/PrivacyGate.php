<?php

namespace App\Domains\Accounts\Services;

use App\Domains\Church\Models\ChurchMembership;
use App\Domains\Friends\Models\Friendship;
use App\Enums\Visibility;
use App\Models\User;

/**
 * The single authority for "may viewer see / interact with owner?". Every policy,
 * feed query, presence read and invitation check funnels through here so visibility
 * rules (friend / church / block / incognito / friend-only) exist in exactly ONE
 * place. Default-deny: anything not explicitly allowed is denied.
 *
 * A missing privacy_settings row means platform defaults (see Visibility defaults on
 * the migration) — the gate tolerates absence so existing users need no backfill.
 */
class PrivacyGate
{
    /**
     * Core rule: can $viewer see something $owner exposes at $visibility?
     * Blocks (either direction) override everything below them.
     */
    public function canView(User $viewer, User $owner, Visibility $visibility): bool
    {
        if ($viewer->is($owner)) {
            return true; // owners always see their own data
        }

        if (Friendship::blockExistsBetween($viewer->id, $owner->id)) {
            return false; // a block hides everything in both directions
        }

        return match ($visibility) {
            Visibility::PUBLIC  => true,
            Visibility::CHURCH  => $this->shareActiveChurch($viewer, $owner)
                                   || Friendship::areFriends($viewer->id, $owner->id),
            Visibility::FRIENDS => Friendship::areFriends($viewer->id, $owner->id),
            Visibility::PRIVATE => false,
        };
    }

    /** Profile visibility, honoring the owner's configured tier (default: friends). */
    public function canViewProfile(User $viewer, User $owner): bool
    {
        return $this->canView($viewer, $owner, $this->visibilityFor($owner, 'profile_visibility'));
    }

    /** Activity-feed visibility, honoring the owner's configured tier (default: friends). */
    public function canViewActivity(User $viewer, User $owner): bool
    {
        return $this->canView($viewer, $owner, $this->visibilityFor($owner, 'activity_visibility'));
    }

    /**
     * Presence visibility. Incognito hides presence from everyone but the owner,
     * regardless of the configured tier.
     */
    public function canViewPresence(User $viewer, User $owner): bool
    {
        if ($viewer->is($owner)) {
            return true;
        }
        if ($owner->privacy?->incognito) {
            return false;
        }

        return $this->canView($viewer, $owner, $this->visibilityFor($owner, 'presence_visibility'));
    }

    /**
     * May $actor initiate an interaction (friend request, invitation, message) with
     * $target? Blocked pairs never interact; friend-only mode restricts inbound
     * interaction to existing friends.
     */
    public function canInteract(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }
        if (Friendship::blockExistsBetween($actor->id, $target->id)) {
            return false;
        }
        if ($target->privacy?->friend_only_mode && ! Friendship::areFriends($actor->id, $target->id)) {
            return false;
        }

        return true;
    }

    /** True when both users hold an active membership in the same church. */
    public function shareActiveChurch(User $a, User $b): bool
    {
        $churches = ChurchMembership::query()
            ->where('user_id', $a->id)
            ->where('status', ChurchMembership::STATUS_ACTIVE)
            ->pluck('church_id');

        if ($churches->isEmpty()) {
            return false;
        }

        return ChurchMembership::query()
            ->where('user_id', $b->id)
            ->where('status', ChurchMembership::STATUS_ACTIVE)
            ->whereIn('church_id', $churches)
            ->exists();
    }

    /** Read a visibility column off the owner's settings, falling back to FRIENDS. */
    private function visibilityFor(User $owner, string $column): Visibility
    {
        return $owner->privacy?->{$column} ?? Visibility::FRIENDS;
    }
}
