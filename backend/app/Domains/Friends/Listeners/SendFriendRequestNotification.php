<?php

namespace App\Domains\Friends\Listeners;

use App\Domains\Friends\Events\FriendRequestSent;
use App\Domains\Friends\Notifications\FriendRequestNotification;
use App\Domains\Notifications\Listeners\CommunityNotifier;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Notifies the recipient of an incoming friend request. Idempotent (keyed by actor). */
class SendFriendRequestNotification extends CommunityNotifier implements ShouldQueue
{
    public function handle(FriendRequestSent $event): void
    {
        $actor  = User::find($event->actorId);
        $target = User::find($event->targetId);
        if (! $actor || ! $target) {
            return;
        }

        $this->sendOnce($target, new FriendRequestNotification($actor), [
            'data->type'     => 'friend_request',
            'data->actor_id' => $actor->id,
        ]);
    }
}
