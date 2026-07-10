<?php

namespace App\Domains\Invitations\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Personal email delivery of a group invitation LINK to someone who may have no
 * account yet (on-demand notifiable: Notification::route('mail', …) — mail-only,
 * no database row for a person who isn't a user). Scalar payload by design.
 * The link remains the credential — single-use, 14-day expiry, revocable and
 * previewable like every other link: email is a delivery channel, not a second
 * invitation system.
 */
class GroupInviteEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $inviterName,
        public readonly string $groupName,
        public readonly ?string $churchName,
        public readonly string $token,
        public readonly ?string $message = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $joinUrl = rtrim((string) config('church.frontend_url'), '/').'/#join?token='.$this->token;

        return (new MailMessage)
            ->subject($this->inviterName.' invited you to '.$this->groupName)
            ->greeting('Peace be with you.')
            ->line($this->inviterName.' invited you to join '.$this->groupName
                .($this->churchName ? ' at '.$this->churchName : '').'.')
            ->when($this->message, fn ($m) => $m->line('“'.$this->message.'”'))
            ->action('Join '.$this->groupName, $joinUrl)
            ->line('This personal invitation can be used once and expires in 14 days.');
    }
}
