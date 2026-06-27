<?php

namespace App\Domains\Invitations\Listeners;

use App\Domains\Invitations\Events\InvitationSent;
use App\Domains\Invitations\Models\Invitation;
use App\Domains\Invitations\Notifications\InvitationReceivedNotification;
use App\Domains\Notifications\Listeners\CommunityNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Notifies the invitee when an invitation is sent. Idempotent (keyed by correlation). */
class SendInvitationNotification extends CommunityNotifier implements ShouldQueue
{
    public function handle(InvitationSent $event): void
    {
        $invitation = Invitation::with(['invitee', 'inviter'])->find($event->invitationId);
        if (! $invitation || ! $invitation->invitee) {
            return; // invitation or recipient gone — nothing to deliver
        }

        $this->sendOnce(
            $invitation->invitee,
            new InvitationReceivedNotification($invitation),
            ['correlation_id' => $event->correlationId],
        );
    }
}
