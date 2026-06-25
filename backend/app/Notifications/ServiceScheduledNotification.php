<?php

namespace App\Notifications;

use App\Models\ServiceSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ServiceScheduledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ServiceSession $session,
        public ?string $recipientName  = null,
        public ?string $recipientEmail = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $base = rtrim((string) config('church.frontend_url'), '/');
        $url  = $base . '?session=' . $this->session->issueResumeToken();
        $name = $this->recipientName
            ?: (property_exists($notifiable, 'name') ? ($notifiable->name ?: 'friend') : 'friend');
        $when = $this->session->scheduled_at
            ? $this->session->scheduled_at->setTimezone('UTC')->format('l, F j \a\t g:i A \U\T\C')
            : 'your chosen time';

        return (new MailMessage)
            ->subject('Your worship service is booked')
            ->greeting("Peace be with you, {$name}.")
            ->line("Your personal worship service has been reserved for **{$when}**.")
            ->line("We'll send you another email as soon as the service is ready and the doors are open — you won't need to do anything in the meantime.")
            ->action('Visit AI Virtual Church', $url)
            ->salutation('Grace and peace, ' . config('app.name'));
    }
}
