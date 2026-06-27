<?php

namespace App\Domains\Invitations\Notifications;

use App\Domains\Invitations\Models\Invitation;
use App\Domains\Notifications\Notifications\CommunityNotification;
use App\Enums\NotificationPriority;

/** "X accepted/declined your invitation." Sent to the inviter. Normal → in-app only. */
class InvitationResponseNotification extends CommunityNotification
{
    public function __construct(public readonly Invitation $invitation)
    {
    }

    public function priority(): NotificationPriority
    {
        return NotificationPriority::NORMAL;
    }

    public function correlationId(): ?string
    {
        return $this->invitation->correlation_id;
    }

    protected function databasePayload(object $notifiable): array
    {
        return [
            'type'          => 'invitation_'.$this->invitation->status->value, // invitation_accepted / _declined
            'invitation_id' => $this->invitation->id,
            'activity'      => $this->invitation->activity->value,
            'invitee_id'    => $this->invitation->invitee_id,
            'invitee_name'  => $this->invitation->invitee?->name,
            'status'        => $this->invitation->status->value,
        ];
    }
}
