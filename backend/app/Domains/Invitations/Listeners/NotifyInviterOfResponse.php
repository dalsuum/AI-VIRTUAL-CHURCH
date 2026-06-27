<?php

namespace App\Domains\Invitations\Listeners;

use App\Domains\Invitations\Events\InvitationAccepted;
use App\Domains\Invitations\Events\InvitationDeclined;
use App\Domains\Invitations\Models\Invitation;
use App\Domains\Invitations\Notifications\InvitationResponseNotification;
use App\Domains\Notifications\Listeners\CommunityNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Notifies the inviter when their invitation is accepted or declined. One listener for
 * both events (same notification, status carried on the invitation). Idempotent, keyed
 * by correlation + the resolved status type so accept and decline never collide.
 */
class NotifyInviterOfResponse extends CommunityNotifier implements ShouldQueue
{
    public function handle(InvitationAccepted|InvitationDeclined $event): void
    {
        $invitation = Invitation::with(['inviter', 'invitee'])->find($event->invitationId);
        if (! $invitation || ! $invitation->inviter) {
            return;
        }

        $notification = new InvitationResponseNotification($invitation);

        $this->sendOnce($invitation->inviter, $notification, [
            'correlation_id' => $event->correlationId,
            'data->type'     => 'invitation_'.$invitation->status->value,
        ]);
    }
}
