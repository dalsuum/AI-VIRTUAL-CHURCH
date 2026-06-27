<?php

namespace App\Domains\Friends\Listeners;

use App\Domains\Friends\Events\FriendRequestAccepted;
use App\Domains\Friends\Notifications\FriendRequestAcceptedNotification;
use App\Domains\Notifications\Listeners\CommunityNotifier;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Notifies the original requester that their friend request was accepted. In
 * FriendRequestAccepted the actor is the accepter and the target is the requester, so
 * the requester is notified that the accepter accepted. Idempotent (keyed by actor).
 */
class NotifyFriendRequestAccepted extends CommunityNotifier implements ShouldQueue
{
    public function handle(FriendRequestAccepted $event): void
    {
        $accepter  = User::find($event->actorId);
        $requester = User::find($event->targetId);
        if (! $accepter || ! $requester) {
            return;
        }

        $this->sendOnce($requester, new FriendRequestAcceptedNotification($accepter), [
            'data->type'     => 'friend_request_accepted',
            'data->actor_id' => $accepter->id,
        ]);
    }
}
