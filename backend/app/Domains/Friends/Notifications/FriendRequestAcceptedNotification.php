<?php

namespace App\Domains\Friends\Notifications;

use App\Domains\Notifications\Notifications\CommunityNotification;
use App\Enums\NotificationPriority;
use App\Models\User;

/** "X accepted your friend request." Low priority → in-app only. */
class FriendRequestAcceptedNotification extends CommunityNotification
{
    public function __construct(public readonly User $actor)
    {
    }

    public function priority(): NotificationPriority
    {
        return NotificationPriority::LOW;
    }

    protected function databasePayload(object $notifiable): array
    {
        return [
            'type'       => 'friend_request_accepted',
            'actor_id'   => $this->actor->id,
            'actor_name' => $this->actor->name,
        ];
    }
}
