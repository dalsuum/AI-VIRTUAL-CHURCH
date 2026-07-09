<?php

namespace App\Domains\Invitations\Services;

use App\Domains\Accounts\Services\PrivacyGate;
use App\Domains\Church\Models\ChurchMembership;
use App\Domains\Groups\Models\Group;
use App\Domains\Groups\Models\GroupMembership;
use App\Domains\Invitations\Events\InvitationAccepted;
use App\Domains\Invitations\Events\InvitationCancelled;
use App\Domains\Invitations\Events\InvitationDeclined;
use App\Domains\Invitations\Events\InvitationExpired;
use App\Domains\Invitations\Events\InvitationRedeemed;
use App\Domains\Invitations\Events\InvitationSent;
use App\Domains\Invitations\Exceptions\InvitationException;
use App\Domains\Invitations\Models\Invitation;
use App\Enums\ChurchRole;
use App\Enums\GroupRole;
use App\Enums\InvitationActivity;
use App\Enums\InvitationKind;
use App\Enums\InvitationStatus;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONLY component allowed to mutate invitation state. Every transition
 * (pending → accepted/declined/cancelled/expired) flows through transition(), giving
 * one place for: audit, expiration enforcement, session creation (later phases),
 * event publishing and idempotency. Controllers, listeners, jobs and notifications
 * never write invitation status directly.
 *
 * Each invitation carries a correlation_id that every record it spawns (session,
 * notifications, audit, analytics) reuses, so a whole workflow is traceable.
 */
class InvitationService
{
    public function __construct(private readonly PrivacyGate $privacy)
    {
    }

    /** Create a PENDING invitation and announce it. Default expiry is 7 days. */
    public function send(
        User $inviter,
        User $invitee,
        InvitationActivity $activity,
        ?CarbonInterface $scheduledAt = null,
        ?string $timezone = null,
        ?string $message = null,
        ?CarbonInterface $expiresAt = null,
    ): Invitation {
        if ($inviter->is($invitee)) {
            throw InvitationException::conflict('You cannot invite yourself.');
        }
        if (! $this->privacy->canInteract($inviter, $invitee)) {
            throw InvitationException::forbidden('You cannot invite this user.');
        }

        $invitation = Invitation::create([
            'correlation_id' => (string) Str::uuid(),
            'inviter_id'     => $inviter->id,
            'invitee_id'     => $invitee->id,
            'kind'           => InvitationKind::DIRECT,
            'activity'       => $activity,
            'status'         => InvitationStatus::PENDING,
            'scheduled_at'   => $scheduledAt,
            'timezone'       => $timezone ?? $inviter->timezone,
            'message'        => $message,
            'expires_at'     => $expiresAt ?? now()->addDays(7),
        ]);

        InvitationSent::dispatch($invitation->id, $invitation->correlation_id);

        return $invitation;
    }

    /**
     * Mint an open LINK invitation into a group (v1.3). No addressee, no
     * InvitationSent — the unguessable token is shared out of band (URL / QR).
     * The link stays PENDING while redeemed up to max_uses times; revocation is
     * the ordinary cancel transition and the expiry sweep applies unchanged.
     */
    public function sendLink(
        User $inviter,
        Group $group,
        ?int $maxUses = null,
        ?CarbonInterface $expiresAt = null,
        ?string $message = null,
    ): Invitation {
        if (! $inviter->can('manage', $group)) {
            throw InvitationException::forbidden('You cannot create invitation links for this group.');
        }

        return Invitation::create([
            'correlation_id' => (string) Str::uuid(),
            'inviter_id'     => $inviter->id,
            'invitee_id'     => null,
            'kind'           => InvitationKind::LINK,
            'token'          => Str::random(48),
            'max_uses'       => $maxUses,
            'activity'       => InvitationActivity::GROUP_MEMBERSHIP,
            'invitable_type' => $group->getMorphClass(),
            'invitable_id'   => $group->id,
            'status'         => InvitationStatus::PENDING,
            'message'        => $message,
            'expires_at'     => $expiresAt ?? now()->addDays(7),
        ]);
    }

    /**
     * Join the group behind a LINK invitation. Same discipline as transition():
     * row lock, expiry auto-sweep, idempotency (an already-active member re-tapping
     * the link is a no-op that costs no use), one past-tense event per join.
     */
    public function redeem(User $actor, Invitation $invitation): GroupMembership
    {
        return DB::transaction(function () use ($actor, $invitation) {
            /** @var Invitation $fresh */
            $fresh = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if ($fresh->kind !== InvitationKind::LINK) {
                throw InvitationException::conflict('This invitation is not a shareable link.');
            }
            if ($fresh->status === InvitationStatus::CANCELLED) {
                throw InvitationException::conflict('This invitation link has been revoked.');
            }
            if ($fresh->status !== InvitationStatus::PENDING) {
                throw InvitationException::conflict('This invitation link is no longer valid.');
            }
            if ($fresh->hasExpired()) {
                // Refuse only. The durable flip to EXPIRED belongs to the
                // invitations:expire sweep — a write here would be rolled back
                // by this failing transaction anyway.
                throw InvitationException::conflict('This invitation link has expired.');
            }

            /** @var Group $group */
            $group = $fresh->invitable;

            $membership = GroupMembership::query()
                ->where('group_id', $group->id)->where('user_id', $actor->id)
                ->lockForUpdate()->first();
            if ($membership && $membership->status === GroupMembership::STATUS_ACTIVE) {
                return $membership; // idempotent no-op — no use_count charge
            }

            if (! $fresh->hasRemainingUses()) {
                throw InvitationException::conflict('This invitation link has no remaining uses.');
            }

            // An outsider joining a group becomes a GUEST of its church (least
            // privilege — full membership stays a pastoral decision). Existing
            // church rows, whatever their status, are left untouched.
            ChurchMembership::firstOrCreate(
                ['church_id' => $group->church_id, 'user_id' => $actor->id],
                ['role' => ChurchRole::GUEST, 'status' => ChurchMembership::STATUS_ACTIVE, 'joined_at' => now()],
            );

            if ($membership) {
                // Rejoin after removal/leaving: reactivate the canonical row as a
                // plain member (prior leadership does not survive a rejoin).
                $membership->forceFill([
                    'role'      => GroupRole::MEMBER,
                    'status'    => GroupMembership::STATUS_ACTIVE,
                    'joined_at' => now(),
                ])->save();
            } else {
                $membership = GroupMembership::create([
                    'group_id'  => $group->id,
                    'user_id'   => $actor->id,
                    'role'      => GroupRole::MEMBER,
                    'status'    => GroupMembership::STATUS_ACTIVE,
                    'joined_at' => now(),
                ]);
            }

            $fresh->forceFill(['use_count' => $fresh->use_count + 1])->save();
            InvitationRedeemed::dispatch($fresh->id, $fresh->correlation_id, $actor->id);

            return $membership;
        });
    }

    /** Invitee accepts. (Session creation hangs off InvitationAccepted in later phases.) */
    public function accept(User $actor, Invitation $invitation): Invitation
    {
        return $this->transition($invitation, InvitationStatus::ACCEPTED, $actor, role: 'invitee');
    }

    /** Invitee declines. */
    public function decline(User $actor, Invitation $invitation): Invitation
    {
        return $this->transition($invitation, InvitationStatus::DECLINED, $actor, role: 'invitee');
    }

    /** Inviter cancels their own pending invitation. */
    public function cancel(User $actor, Invitation $invitation): Invitation
    {
        return $this->transition($invitation, InvitationStatus::CANCELLED, $actor, role: 'inviter');
    }

    /** Sweep due invitations to EXPIRED. Called by the scheduled job; system-driven. */
    public function expireDue(int $limit = 500): int
    {
        $due = Invitation::query()
            ->where('status', InvitationStatus::PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->limit($limit)
            ->get();

        $count = 0;
        foreach ($due as $invitation) {
            $this->transition($invitation, InvitationStatus::EXPIRED, actor: null, role: 'system');
            $count++;
        }

        return $count;
    }

    /**
     * The single transition method. Locks the row, enforces actor authority and
     * expiry, writes status once and publishes the matching past-tense event.
     *
     * Idempotent: re-running a transition that already happened returns the row
     * WITHOUT re-dispatching (safe for queue retries / double taps). A transition
     * from one terminal state to a different one is a 409 conflict.
     */
    private function transition(Invitation $invitation, InvitationStatus $to, ?User $actor, string $role): Invitation
    {
        return DB::transaction(function () use ($invitation, $to, $actor, $role) {
            /** @var Invitation $fresh */
            $fresh = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if ($fresh->status === $to) {
                return $fresh; // idempotent no-op
            }
            if ($fresh->status->isTerminal()) {
                throw InvitationException::conflict('This invitation has already been '.$fresh->status->value.'.');
            }

            // Auto-expire a stale pending invitation rather than accept/decline it.
            if ($role !== 'system' && $fresh->hasExpired()) {
                $this->write($fresh, InvitationStatus::EXPIRED);
                InvitationExpired::dispatch($fresh->id, $fresh->correlation_id);
                throw InvitationException::conflict('This invitation has expired.');
            }

            $this->authorize($fresh, $actor, $role);
            $this->write($fresh, $to);

            match ($to) {
                InvitationStatus::ACCEPTED  => InvitationAccepted::dispatch($fresh->id, $fresh->correlation_id),
                InvitationStatus::DECLINED  => InvitationDeclined::dispatch($fresh->id, $fresh->correlation_id),
                InvitationStatus::CANCELLED => InvitationCancelled::dispatch($fresh->id, $fresh->correlation_id),
                InvitationStatus::EXPIRED   => InvitationExpired::dispatch($fresh->id, $fresh->correlation_id),
                default                     => null,
            };

            return $fresh;
        });
    }

    /** Only the invitee may accept/decline; only the inviter may cancel; system expires.
     *  Exception: a LINK is revoked (cancelled) by its creator OR anyone who can manage
     *  the target group — GroupPolicy::manage owns that rule, not this service. */
    private function authorize(Invitation $invitation, ?User $actor, string $role): void
    {
        if ($role === 'system') {
            return;
        }
        if ($role === 'inviter' && $invitation->kind === InvitationKind::LINK
            && $actor && $invitation->invitable && $actor->can('manage', $invitation->invitable)) {
            return;
        }
        $expectedId = $role === 'invitee' ? $invitation->invitee_id : $invitation->inviter_id;
        if (! $actor || $actor->id !== $expectedId) {
            throw InvitationException::forbidden('You cannot perform this action on this invitation.');
        }
    }

    private function write(Invitation $invitation, InvitationStatus $to): void
    {
        $invitation->forceFill([
            'status'       => $to,
            'responded_at' => now(),
        ])->save();
    }
}
