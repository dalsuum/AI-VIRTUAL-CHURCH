<?php

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Channels\CommunityDatabaseChannel;
use App\Enums\NotificationPriority;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Base for every community notification — the "router" layer. The domain never names
 * channels; it sets a PRIORITY and the priority decides the channels (see
 * NotificationPriority::channels()). Adding WebSocket/push later is a change here and
 * in the enum, never in a listener or domain service.
 *
 * Subclasses provide: priority(), a database payload, and (for high/critical) toMail().
 * correlationId() ties the notification to its originating workflow; override it when a
 * correlation id exists (invitation-driven), else it stays null.
 */
abstract class CommunityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    abstract public function priority(): NotificationPriority;

    /** The stored in-app payload. Always include a stable 'type' for the client. */
    abstract protected function databasePayload(object $notifiable): array;

    public function correlationId(): ?string
    {
        return null;
    }

    public function via(object $notifiable): array
    {
        return collect($this->priority()->channels())
            ->map(fn (string $c) => $c === 'database' ? CommunityDatabaseChannel::class : $c)
            ->reject(fn (string $c) => $c === 'mail' && blank($notifiable->email ?? null))
            ->values()->all();
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->databasePayload($notifiable);
    }
}
