<?php

namespace App\Domains\Bible\Services;

use App\Domains\Bible\Events\ReadingSessionStarted;
use App\Domains\Bible\Exceptions\ReadingException;
use App\Domains\Bible\Models\ReadingParticipant;
use App\Domains\Bible\Models\ReadingPlan;
use App\Domains\Bible\Models\ReadingSession;
use App\Domains\Groups\Models\Group;
use App\Enums\GroupRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONLY mutator of shared reading sessions. A session COORDINATES a group around
 * an EXISTING reading plan — it owns no reading progress. Every participant reads
 * through their own user_reading_plans enrollment (created via ReadingPlanService,
 * the sole progress mutator), so streaks, reminders and reading events keep working
 * whether or not a session exists. Governing invariant: never a second reading model.
 *
 * Authority reuses GroupPolicy: manage to create/steer a session, membership to join.
 */
class ReadingSessionService
{
    /** Legal moves; same-state is an idempotent no-op, anything absent is a 409. */
    private const TRANSITIONS = [
        ReadingSession::STATUS_PLANNED => [ReadingSession::STATUS_ACTIVE, ReadingSession::STATUS_ABANDONED],
        ReadingSession::STATUS_ACTIVE  => [ReadingSession::STATUS_PAUSED, ReadingSession::STATUS_COMPLETED, ReadingSession::STATUS_ABANDONED],
        ReadingSession::STATUS_PAUSED  => [ReadingSession::STATUS_ACTIVE, ReadingSession::STATUS_COMPLETED, ReadingSession::STATUS_ABANDONED],
    ];

    public function __construct(private readonly ReadingPlanService $plans)
    {
    }

    /** One open (non-terminal) session per group — a group reads one plan together. */
    public function createForGroup(User $creator, Group $group, ReadingPlan $plan): ReadingSession
    {
        if (! $creator->can('manage', $group)) {
            throw ReadingException::forbidden('You cannot create reading sessions for this group.');
        }

        return DB::transaction(function () use ($creator, $group, $plan) {
            $open = ReadingSession::query()
                ->where('group_id', $group->id)
                ->whereNotIn('status', ReadingSession::TERMINAL)
                ->lockForUpdate()
                ->exists();
            if ($open) {
                throw ReadingException::conflict('This group already has an open reading session.');
            }

            return ReadingSession::create([
                'correlation_id'  => (string) Str::uuid(),
                'group_id'        => $group->id,
                'reading_plan_id' => $plan->id,
                'created_by'      => $creator->id,
                'status'          => ReadingSession::STATUS_PLANNED,
            ]);
        });
    }

    /**
     * Join a session (active group members). The member's own enrollment IS their
     * participation: enrollment goes through ReadingPlanService, so its one-active-
     * plan rule applies — joining while a different plan is active is a 409, and
     * joining the plan you already read reuses the enrollment. Idempotent per user.
     */
    public function join(User $user, ReadingSession $session): ReadingParticipant
    {
        return DB::transaction(function () use ($user, $session) {
            /** @var ReadingSession $fresh */
            $fresh = ReadingSession::query()->with('plan')->lockForUpdate()->findOrFail($session->id);

            if ($fresh->isTerminal()) {
                throw ReadingException::conflict('This reading session has ended.');
            }
            if (! $user->hasGroupRole($fresh->group_id, GroupRole::MEMBER)) {
                throw ReadingException::forbidden('Only group members can join this reading session.');
            }

            $existing = ReadingParticipant::query()
                ->where('reading_session_id', $fresh->id)->where('user_id', $user->id)
                ->first();
            if ($existing) {
                return $existing;
            }

            $enrollment = $this->plans->enroll($user, $fresh->plan);

            return ReadingParticipant::create([
                'reading_session_id'   => $fresh->id,
                'user_id'              => $user->id,
                'user_reading_plan_id' => $enrollment->id,
                'joined_at'            => now(),
            ]);
        });
    }

    public function start(User $actor, ReadingSession $session): ReadingSession
    {
        return $this->transition($actor, $session, ReadingSession::STATUS_ACTIVE);
    }

    public function pause(User $actor, ReadingSession $session): ReadingSession
    {
        return $this->transition($actor, $session, ReadingSession::STATUS_PAUSED);
    }

    public function resume(User $actor, ReadingSession $session): ReadingSession
    {
        return $this->transition($actor, $session, ReadingSession::STATUS_ACTIVE);
    }

    public function complete(User $actor, ReadingSession $session): ReadingSession
    {
        return $this->transition($actor, $session, ReadingSession::STATUS_COMPLETED);
    }

    public function abandon(User $actor, ReadingSession $session): ReadingSession
    {
        return $this->transition($actor, $session, ReadingSession::STATUS_ABANDONED);
    }

    /** The single transition method — same discipline as InvitationService. */
    private function transition(User $actor, ReadingSession $session, string $to): ReadingSession
    {
        return DB::transaction(function () use ($actor, $session, $to) {
            /** @var ReadingSession $fresh */
            $fresh = ReadingSession::query()->lockForUpdate()->findOrFail($session->id);

            if (! $actor->can('manage', $fresh->group)) {
                throw ReadingException::forbidden('Only group leaders can manage this reading session.');
            }
            if ($fresh->status === $to) {
                return $fresh; // idempotent no-op
            }
            if (! in_array($to, self::TRANSITIONS[$fresh->status] ?? [], true)) {
                throw ReadingException::conflict('This reading session is already '.$fresh->status.'.');
            }

            $goingLive = $fresh->status === ReadingSession::STATUS_PLANNED
                && $to === ReadingSession::STATUS_ACTIVE;

            $fresh->forceFill([
                'status'       => $to,
                'started_at'   => $goingLive ? now() : $fresh->started_at,
                'completed_at' => $to === ReadingSession::STATUS_COMPLETED ? now() : $fresh->completed_at,
            ])->save();

            if ($goingLive) {
                ReadingSessionStarted::dispatch(
                    $actor->id, $fresh->id, $fresh->group_id, $fresh->reading_plan_id, $fresh->correlation_id,
                );
            }

            return $fresh;
        });
    }
}
