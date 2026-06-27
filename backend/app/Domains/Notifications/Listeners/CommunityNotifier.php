<?php

namespace App\Domains\Notifications\Listeners;

use App\Domains\Notifications\Notifications\CommunityNotification;
use App\Models\User;

/**
 * Base for queued notification listeners. Queued listeners may run more than once
 * (retries, at-least-once delivery), so delivery is guarded: sendOnce() skips if a
 * matching in-app notification already exists for the recipient. The $match keys are
 * columns/JSON paths on the notifications table (e.g. ['correlation_id' => $uuid] for
 * invitation workflows, ['data->actor_id' => $id] for friendship ones).
 */
abstract class CommunityNotifier
{
    protected function sendOnce(User $notifiable, CommunityNotification $notification, array $match): void
    {
        $already = $notifiable->notifications()
            ->where('type', $notification::class)
            ->where(function ($q) use ($match) {
                foreach ($match as $column => $value) {
                    $q->where($column, $value);
                }
            })
            ->exists();

        if (! $already) {
            $notifiable->notify($notification);
        }
    }
}
