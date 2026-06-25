<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Welcome + activation email. Laravel's MailMessage renders a responsive HTML email
 * (with the Activate button) and an automatic plain-text fallback. The link points at
 * the GET /activate endpoint, which validates the token and shows the result page.
 */
class AccountActivationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string  $token,
        public ?string $recipientName = null,
    ) {}

    public function via(): array { return ['mail']; }

    public function toMail(): MailMessage
    {
        $hours = (int) config('account.verification_expires_hours', 24);
        $url   = rtrim((string) config('app.url'), '/') . '/activate?token=' . $this->token;

        return (new MailMessage)
            ->subject('Activate your AI Virtual Church account')
            ->greeting('Welcome' . ($this->recipientName ? ", {$this->recipientName}" : '') . '!')
            ->line('Thanks for creating an AI Virtual Church account. Please confirm your email address to activate your account and receive your monthly Member tokens.')
            ->action('Activate My Account', $url)
            ->line("This activation link expires in {$hours} hours. If it expires, simply register again.")
            ->line('If you did not create this account, you can safely ignore this email.')
            ->salutation('— AI Virtual Church');
    }
}
