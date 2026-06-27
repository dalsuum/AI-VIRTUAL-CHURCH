<?php

namespace App\Domains\Accounts\Services;

use App\Domains\Accounts\Models\Presence;
use App\Domains\Friends\Models\Friendship;
use App\Enums\FriendStatus;
use App\Enums\PresenceActivity;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The single boundary for presence. Presence is EPHEMERAL: today it is persisted in the
 * presences table, but callers must go through this service rather than touching the
 * model, so Phase 6 can make Redis the authoritative store (with the table kept only for
 * durable last_seen / last-activity / recovery) without changing a single controller.
 *
 * All cross-user reads pass through PrivacyGate::canViewPresence (which also enforces
 * incognito and blocks), so visibility lives in one place.
 */
class PresenceService
{
    public function __construct(private readonly PrivacyGate $privacy)
    {
    }

    /** Record a heartbeat: mark the user online, stamp last_seen and optional activity. */
    public function heartbeat(User $user, ?PresenceActivity $activity = null, ?string $activityRef = null): Presence
    {
        return Presence::updateOrCreate(
            ['user_id' => $user->id],
            [
                'status'           => 'online',
                'current_activity' => $activity,
                'activity_ref'     => $activityRef,
                'last_seen_at'     => now(),
            ],
        );
    }

    /** Mark a user offline (used by the Phase 6 reaper and on explicit sign-out). */
    public function markOffline(User $user): void
    {
        Presence::where('user_id', $user->id)->update(['status' => 'offline', 'last_seen_at' => now()]);
    }

    /** The user's own presence projection (always visible to themselves). */
    public function forSelf(User $user): array
    {
        return $this->project($user->presence);
    }

    /** A projection of $owner's presence as seen by $viewer, or null if not permitted. */
    public function visibleTo(User $viewer, User $owner): ?array
    {
        if (! $this->privacy->canViewPresence($viewer, $owner)) {
            return null;
        }

        return $this->project($owner->presence);
    }

    /** Visible presence of the viewer's accepted friends, keyed by friend id. */
    public function friendsPresence(User $viewer): Collection
    {
        $friendIds = Friendship::query()
            ->where('status', FriendStatus::ACCEPTED)
            ->where(fn ($q) => $q->where('user_id', $viewer->id)->orWhere('friend_id', $viewer->id))
            ->get()
            ->map(fn (Friendship $f) => $f->user_id === $viewer->id ? $f->friend_id : $f->user_id);

        return User::with(['presence', 'privacy'])
            ->whereIn('id', $friendIds)
            ->get()
            ->mapWithKeys(fn (User $friend) => [$friend->id => $this->visibleTo($viewer, $friend)])
            ->reject(fn ($presence) => $presence === null);
    }

    /** Stable presence projection. Defaults to offline when no row exists yet. */
    private function project(?Presence $presence): array
    {
        return [
            'status'       => $presence?->status ?? 'offline',
            'activity'     => $presence?->current_activity?->value,
            'activity_ref' => $presence?->activity_ref,
            'last_seen_at' => optional($presence?->last_seen_at)->toIso8601String(),
        ];
    }
}
