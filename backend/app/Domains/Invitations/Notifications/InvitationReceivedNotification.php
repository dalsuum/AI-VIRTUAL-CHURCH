<?php

namespace App\Domains\Invitations\Notifications;

use App\Domains\Invitations\Models\Invitation;
use App\Domains\Notifications\Notifications\CommunityNotification;
use App\Enums\InvitationKind;
use App\Enums\NotificationPriority;
use Illuminate\Notifications\Messages\MailMessage;

/** "X invited you to <activity>." Sent to the invitee — or, for a join REQUEST,
 *  "X asked to join <group>." sent to each group leader. High priority → in-app + email. */
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
        $isRequest = $this->invitation->kind === InvitationKind::REQUEST;

        return [
            'type'          => $isRequest ? 'join_request_received' : 'invitation_received',
            'invitation_id' => $this->invitation->id,
            'kind'          => $this->invitation->kind->value,
            'activity'      => $this->invitation->activity->value,
            'inviter_id'    => $this->invitation->inviter_id,
            'inviter_name'  => $this->invitation->inviter?->name,
            'group_id'      => $isRequest ? $this->invitation->invitable?->id : null,
            'group_name'    => $isRequest ? $this->invitation->invitable?->name : null,
            'message'       => $this->invitation->message,
            'scheduled_at'  => optional($this->invitation->scheduled_at)->toIso8601String(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('church.frontend_url'), '/');
        $name     = $this->invitation->inviter?->name ?? 'A member';

        if ($this->invitation->kind === InvitationKind::REQUEST) {
            return (new MailMessage)
                ->subject('New request to join '.($this->invitation->invitable?->name ?? 'your group'))
                ->greeting('Peace be with you.')
                ->line($name.' asked to join '.($this->invitation->invitable?->name ?? 'your group').'.')
                ->when($this->invitation->message, fn ($m) => $m->line('“'.$this->invitation->message.'”'))
                ->action('Review requests', $frontend.'/invitations');
        }

        $activity = str_replace('_', ' ', $this->invitation->activity->value);

        return (new MailMessage)
            ->subject('You have a new invitation')
            ->greeting('Peace be with you.')
            ->line($name.' invited you to '.$activity.'.')
            ->when($this->invitation->message, fn ($m) => $m->line('“'.$this->invitation->message.'”'))
            ->action('View invitation', $frontend.'/invitations');
    }
}
