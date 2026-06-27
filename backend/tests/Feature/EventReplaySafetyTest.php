<?php

namespace Tests\Feature;

use App\Domains\Friends\Events\FriendRequestAccepted;
use App\Domains\Friends\Events\FriendRequestSent;
use App\Domains\Invitations\Events\InvitationAccepted;
use App\Domains\Invitations\Events\InvitationSent;
use App\Domains\Invitations\Models\Invitation;
use App\Domains\Invitations\Services\InvitationService;
use App\Enums\InvitationActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The event pipeline must be replay-safe: re-emitting a community event (queue retry,
 * at-least-once delivery, manual replay) must NOT produce duplicate side effects. Today
 * the only side effect is in-app notifications; as feed / analytics / AI-memory / session
 * listeners are added in later phases, each must extend this test with its own no-dup
 * assertion. The shared guarantee is provided by CommunityNotifier::sendOnce().
 */
class EventReplaySafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_replaying_every_event_creates_no_duplicate_notifications(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();

        // Drive real state changes (events fire once here), then replay each event and
        // assert the recipient's notification count is unchanged.
        $a->refresh();

        // Friendship: request + accept.
        app(\App\Domains\Friends\Services\FriendshipService::class)->request($a, $b);
        app(\App\Domains\Friends\Services\FriendshipService::class)->accept($b, $a);

        // Invitation: send + accept.
        $invitation = app(InvitationService::class)->send($a, $b, InvitationActivity::PRAYER);
        app(InvitationService::class)->accept($b, $invitation);
        $invitation->refresh();

        $before = $this->notificationCounts($a, $b);

        // Replay every event a second time, straight at the listeners.
        FriendRequestSent::dispatch($a->id, $b->id, $this->corrFor($b, 'friend_request'));
        FriendRequestAccepted::dispatch($b->id, $a->id, $this->corrFor($a, 'friend_request_accepted'));
        InvitationSent::dispatch($invitation->id, $invitation->correlation_id);
        InvitationAccepted::dispatch($invitation->id, $invitation->correlation_id);

        $this->assertSame($before, $this->notificationCounts($a, $b), 'Replaying events must not duplicate notifications.');
    }

    public function test_events_carry_correlation_id_and_occurred_at(): void
    {
        Event::fake();
        $a = $this->makeUser();
        $b = $this->makeUser();

        app(\App\Domains\Friends\Services\FriendshipService::class)->request($a, $b);

        Event::assertDispatched(FriendRequestSent::class, function (FriendRequestSent $e) {
            return $e->correlationId !== '' && $e->occurredAt instanceof \DateTimeImmutable;
        });
    }

    private function notificationCounts(User ...$users): array
    {
        return collect($users)->mapWithKeys(fn (User $u) => [$u->id => $u->fresh()->notifications()->count()])->all();
    }

    /** Look up the correlation id stored on an already-delivered friend notification. */
    private function corrFor(User $notifiable, string $type): ?string
    {
        return $notifiable->fresh()->notifications()->where('data->type', $type)->value('correlation_id');
    }
}
