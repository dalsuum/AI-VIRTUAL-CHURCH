<?php

namespace App\Domains\Invitations\Notifications;

use App\Domains\Invitations\Models\Invitation;
use App\Domains\Notifications\Notifications\CommunityNotification;
use App\Enums\NotificationPriority;
use Illuminate\Notifications\Messages\MailMessage;

/** "X invited you to <activity>." Sent to the invitee. High priority → in-app + email. */
class InvitationReceivedNotification extends CommunityNotification
{
    public function __construct(public readonly Invitation $invitation)
    {
    }

    public function priority(): NotificationPriority
    {
        return NotificationPriority::HIGH;
    }

    public function correlationId(): ?string
    {
        return $this->invitation->correlation_id;
    }

    protected function databasePayload(object $notifiable): array
    {
        return [
            'type'          => 'invitation_received',
            'invitation_id' => $this->invitation->id,
            'activity'      => $this->invitation->activity->value,
            'inviter_id'    => $this->invitation->inviter_id,
            'inviter_name'  => $this->invitation->inviter?->name,
            'message'       => $this->invitation->message,
            'scheduled_at'  => optional($this->invitation->scheduled_at)->toIso8601String(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $activity = str_replace('_', ' ', $this->invitation->activity->value);

        return (new MailMessage)
            ->subject('You have a new invitation')
            ->greeting('Peace be with you.')
            ->line(($this->invitation->inviter?->name ?? 'A member').' invited you to '.$activity.'.')
            ->when($this->invitation->message, fn ($m) => $m->line('“'.$this->invitation->message.'”'))
            ->action('View invitation', rtrim((string) config('church.frontend_url'), '/').'/invitations');
    }
}
