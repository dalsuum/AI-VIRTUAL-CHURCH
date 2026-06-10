<?php

namespace App\Notifications;

use App\Models\ServiceSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to a registered worshipper the moment their scheduled service comes due
 * (see DispatchDueServices). Queued so the once-a-minute scheduler command never
 * blocks on mail delivery — the running queue:work picks it up.
 */
class ServiceReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ServiceSession $session) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('church.frontend_url'), '/');
        $name = $notifiable->name ?: 'friend';

        return (new MailMessage)
            ->subject('Your worship service is ready — come and attend')
            ->greeting("Peace be with you, {$name}.")
            ->line('The time you set aside for worship has arrived. Your service is being prepared and the doors are open.')
            ->action('Enter the service', $url)
            ->line('Take a quiet moment, then join when you are ready.')
            ->salutation('Grace and peace, ' . config('app.name'));
    }
}
