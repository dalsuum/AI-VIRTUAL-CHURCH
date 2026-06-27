<?php

namespace App\Domains\Bible\Notifications;

use App\Domains\Notifications\Channels\CommunityDatabaseChannel;
use App\Domains\Notifications\Notifications\CommunityNotification;
use App\Enums\NotificationPriority;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Time for your Bible reading." Unlike most community notifications, a reminder is an
 * explicitly USER-CONFIGURED notification, so channel selection follows the user's
 * reminder_settings.channels rather than the priority default — priority (NORMAL) still
 * drives client treatment. Idempotency is keyed on (slot, date) by the scheduler.
 */
class DailyReadingReminderNotification extends CommunityNotification
{
    public function __construct(
        public readonly string $slot,
        public readonly string $date,           // user-local Y-m-d
        public readonly array $today,           // ReadingPlanService::today() payload
        private readonly ?string $correlationId = null,
    ) {
    }

    public function priority(): NotificationPriority
    {
        return NotificationPriority::NORMAL;
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    /** Channels come from the user's reminder preferences (in_app/email; push is Phase 6). */
    public function via(object $notifiable): array
    {
        $channels = $notifiable->reminderSetting?->channels ?: ['in_app'];
        $via = [];
        if (in_array('in_app', $channels, true)) {
            $via[] = CommunityDatabaseChannel::class;
        }
        if (in_array('email', $channels, true) && filled($notifiable->email ?? null)) {
            $via[] = 'mail';
        }

        return $via ?: [CommunityDatabaseChannel::class];
    }

    protected function databasePayload(object $notifiable): array
    {
        return [
            'type'         => 'reading_reminder',
            'slot'         => $this->slot,
            'date'         => $this->date,
            'plan_id'      => $this->today['plan']['id'] ?? null,
            'day_sequence' => $this->today['day']['sequence'] ?? null,
            'day_slug'     => $this->today['day']['slug'] ?? null,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $seq = $this->today['day']['sequence'] ?? null;

        return (new MailMessage)
            ->subject('Time for your Bible reading')
            ->greeting('Peace be with you.')
            ->line($seq ? "Today is day {$seq} of your reading plan." : 'Your daily reading is ready.')
            ->action('Open today’s reading', rtrim((string) config('church.frontend_url'), '/').'/bible/reading');
    }
}
