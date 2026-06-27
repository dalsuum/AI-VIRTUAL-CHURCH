<?php

namespace App\Domains\Notifications\Channels;

use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * The in-app inbox channel, extended to persist the two community columns the stock
 * DatabaseChannel doesn't know about: priority (client treatment) and correlation_id
 * (workflow tracing + listener idempotency). Reads them off the notification when
 * present, so any CommunityNotification populates the columns for free.
 */
class CommunityDatabaseChannel extends DatabaseChannel
{
    protected function buildPayload($notifiable, Notification $notification)
    {
        return array_merge(parent::buildPayload($notifiable, $notification), [
            'priority'       => method_exists($notification, 'priority')
                ? $notification->priority()->value : 'normal',
            'correlation_id' => method_exists($notification, 'correlationId')
                ? $notification->correlationId() : null,
        ]);
    }
}
