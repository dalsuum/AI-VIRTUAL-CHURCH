<?php

namespace App\Providers;

use App\Domains\Friends\Events\FriendRequestAccepted;
use App\Domains\Friends\Events\FriendRequestSent;
use App\Domains\Friends\Listeners\NotifyFriendRequestAccepted;
use App\Domains\Friends\Listeners\SendFriendRequestNotification;
use App\Domains\Invitations\Events\InvitationAccepted;
use App\Domains\Invitations\Events\InvitationDeclined;
use App\Domains\Invitations\Events\InvitationSent;
use App\Domains\Invitations\Listeners\NotifyInviterOfResponse;
use App\Domains\Invitations\Listeners\SendInvitationNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the frozen domain events to their side-effect listeners. Listeners live under
 * app/Domains/* (outside the framework's default auto-discovery path), so the mapping
 * is explicit here — which also serves as a single readable index of who reacts to
 * what. Listeners are side effects ONLY; they never mutate domain state. New
 * subscribers (activity feed, analytics, AI memory) are added as listeners here in
 * later phases without touching the publishing services.
 */
class CommunityEventServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Invitations
        Event::listen(InvitationSent::class, SendInvitationNotification::class);
        Event::listen(InvitationAccepted::class, NotifyInviterOfResponse::class);
        Event::listen(InvitationDeclined::class, NotifyInviterOfResponse::class);

        // Friendships
        Event::listen(FriendRequestSent::class, SendFriendRequestNotification::class);
        Event::listen(FriendRequestAccepted::class, NotifyFriendRequestAccepted::class);
    }
}
