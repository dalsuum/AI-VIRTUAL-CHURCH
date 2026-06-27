<?php

namespace App\Domains\Friends\Notifications;

use App\Domains\Notifications\Notifications\CommunityNotification;
use App\Enums\NotificationPriority;
use App\Models\User;

/** "X sent you a friend request." Normal priority → in-app only. */
class FriendRequestNotification extends CommunityNotification
{
    public function __construct(
        public readonly User $actor,
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

    protected function databasePayload(object $notifiable): array
    {
        return [
            'type'       => 'friend_request',
            'actor_id'   => $this->actor->id,
            'actor_name' => $this->actor->name,
        ];
    }
}
