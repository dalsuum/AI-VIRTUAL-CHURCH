<?php

namespace App\Domains\Invitations\Listeners;

use App\Domains\Groups\Models\GroupMembership;
use App\Domains\Invitations\Events\InvitationSent;
use App\Domains\Invitations\Models\Invitation;
use App\Domains\Invitations\Notifications\InvitationReceivedNotification;
use App\Domains\Notifications\Listeners\CommunityNotifier;
use App\Enums\GroupRole;
use App\Enums\InvitationKind;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Notifies whoever must act on a new invitation: the invitee for DIRECT, the target
 *  group's active leaders for a join REQUEST (LINKs never dispatch InvitationSent —
 *  they have no addressee). Idempotent per recipient (keyed by correlation). */
class SendInvitationNotification extends CommunityNotifier implements ShouldQueue
{
    public function handle(InvitationSent $event): void
    {
        $invitation = Invitation::with(['invitee', 'inviter', 'invitable'])->find($event->invitationId);
        if (! $invitation) {
            return;
        }

        if ($invitation->kind === InvitationKind::REQUEST) {
            $leaders = $invitation->invitable?->members()
                ->wherePivot('role', GroupRole::LEADER->value)
                ->wherePivot('status', GroupMembership::STATUS_ACTIVE)
                ->get() ?? collect();

            foreach ($leaders as $leader) {
                $this->sendOnce($leader, new InvitationReceivedNotification($invitation),
                    ['correlation_id' => $event->correlationId]);
            }

            return; // leaderless groups: managers still see GET /groups/{group}/join-requests
        }

        if (! $invitation->invitee) {
            return; // invitation or recipient gone — nothing to deliver
        }

        $this->sendOnce(
            $invitation->invitee,
            new InvitationReceivedNotification($invitation),
            ['correlation_id' => $event->correlationId],
        );
    }
}
