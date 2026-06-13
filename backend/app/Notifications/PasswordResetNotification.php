<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string  $token,
        public ?string $recipientName = null,
    ) {}

    public function via(): array { return ['mail']; }

    public function toMail(): MailMessage
    {
        $url = config('app.url') . '/#reset?token=' . $this->token;

        return (new MailMessage)
            ->subject('Reset Your Password — AI Virtual Church')
            ->greeting('Hello' . ($this->recipientName ? ", {$this->recipientName}" : '') . '!')
            ->line('We received a request to reset your password. Click the button below to choose a new one.')
            ->action('Reset Password', $url)
            ->line('This link expires in 2 hours. If you did not request a reset, you can safely ignore this email.')
            ->salutation('— AI Virtual Church');
    }
}
