<?php

namespace Tests\Feature;

use App\Domains\Accounts\Models\PrivacySetting;
use App\Domains\Friends\Events\FriendBlocked;
use App\Domains\Friends\Events\FriendRequestAccepted;
use App\Domains\Friends\Events\FriendRequestSent;
use App\Domains\Friends\Models\Friendship;
use App\Enums\FriendStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class FriendshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_then_accept_creates_one_canonical_accepted_row(): void
    {
        Event::fake([FriendRequestSent::class, FriendRequestAccepted::class]);
        $a = $this->makeUser();
        $b = $this->makeUser();

        $this->actingAs($a, 'sanctum')->postJson("/api/friends/{$b->id}/request")->assertCreated();
        Event::assertDispatched(FriendRequestSent::class);

        // Exactly one row, in canonical [min,max] order, status pending.
        $this->assertSame(1, Friendship::count());
        [$lo, $hi] = Friendship::orderedPair($a->id, $b->id);
        $this->assertDatabaseHas('friendships', [
            'user_id' => $lo, 'friend_id' => $hi, 'status' => 'pending', 'requested_by' => $a->id,
        ]);

        $this->actingAs($b, 'sanctum')->postJson("/api/friends/{$a->id}/accept")->assertOk();
        Event::assertDispatched(FriendRequestAccepted::class);
        $this->assertTrue(Friendship::areFriends($a->id, $b->id));
    }

    public function test_invitee_only_can_accept(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $this->actingAs($a, 'sanctum')->postJson("/api/friends/{$b->id}/request")->assertCreated();

        // The requester cannot accept their own request.
        $this->actingAs($a, 'sanctum')->postJson("/api/friends/{$b->id}/accept")->assertForbidden();
    }

    public function test_mutual_requests_shortcut_to_accepted(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $this->actingAs($a, 'sanctum')->postJson("/api/friends/{$b->id}/request")->assertCreated();

        // B requests A back → resolves to accepted, still one row.
        $this->actingAs($b, 'sanctum')->postJson("/api/friends/{$a->id}/request")->assertSuccessful();
        $this->assertSame(1, Friendship::count());
        $this->assertTrue(Friendship::areFriends($a->id, $b->id));
    }

    public function test_remove_soft_deletes_and_preserves_history(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $this->actingAs($a, 'sanctum')->postJson("/api/friends/{$b->id}/request")->assertCreated();
        $this->actingAs($b, 'sanctum')->postJson("/api/friends/{$a->id}/accept")->assertOk();

        $this->actingAs($a, 'sanctum')->deleteJson("/api/friends/{$b->id}")->assertNoContent();

        $this->assertFalse(Friendship::areFriends($a->id, $b->id));
        $this->assertSame(0, Friendship::count());                 // hidden from live queries
        $this->assertSame(1, Friendship::withTrashed()->count());  // audit row preserved
    }

    public function test_re_request_restores_the_same_canonical_row(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $this->actingAs($a, 'sanctum')->postJson("/api/friends/{$b->id}/request")->assertCreated();
        $this->actingAs($b, 'sanctum')->postJson("/api/friends/{$a->id}/reject")->assertNoContent();
        $this->assertSame(1, Friendship::withTrashed()->count());

        // A new request revives the same row rather than creating a duplicate.
        $this->actingAs($a, 'sanctum')->postJson("/api/friends/{$b->id}/request")->assertCreated();
        $this->assertSame(1, Friendship::withTrashed()->count());
        $this->assertSame(1, Friendship::count());
    }

    public function test_block_overrides_everything_and_hides_target(): void
    {
        Event::fake([FriendBlocked::class]);
        $a = $this->makeUser();
        $b = $this->makeUser();
        $this->actingAs($a, 'sanctum')->postJson("/api/friends/{$b->id}/request")->assertCreated();
        $this->actingAs($b, 'sanctum')->postJson("/api/friends/{$a->id}/accept")->assertOk();

        // A blocks B.
        $this->actingAs($a, 'sanctum')->postJson("/api/friends/{$b->id}/block")->assertOk();
        Event::assertDispatched(FriendBlocked::class);
        $this->assertSame('blocked', Friendship::between($a->id, $b->id)->status->value);

        // B can no longer reach A: not.blocked middleware returns 404 (indistinguishable
        // from a missing user — no probing).
        $this->actingAs($b, 'sanctum')->postJson("/api/friends/{$a->id}/request")->assertNotFound();

        // B does not appear in A's search results.
        $res = $this->actingAs($a, 'sanctum')->postJson('/api/friends/search', ['q' => $b->name])->assertOk()->json();
        $this->assertSame([], $res['results']);
    }

    public function test_only_blocker_can_unblock(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $this->actingAs($a, 'sanctum')->postJson("/api/friends/{$b->id}/block")->assertOk();

        // The blocked user cannot lift the block (404 via not.blocked? no — unblock has
        // no middleware, so it reaches the service which forbids it).
        $this->actingAs($b, 'sanctum')->postJson("/api/friends/{$a->id}/unblock")->assertForbidden();

        // The blocker can.
        $this->actingAs($a, 'sanctum')->postJson("/api/friends/{$b->id}/unblock")->assertNoContent();
        $this->assertFalse(Friendship::blockExistsBetween($a->id, $b->id));
    }

    public function test_friend_only_mode_blocks_non_friend_request(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        PrivacySetting::create(['user_id' => $b->id, 'friend_only_mode' => true]);

        $this->actingAs($a, 'sanctum')->postJson("/api/friends/{$b->id}/request")->assertForbidden();
    }

    public function test_favorite_requires_friendship_and_is_one_sided(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();

        // Cannot favorite a non-friend.
        $this->actingAs($a, 'sanctum')->postJson("/api/friends/{$b->id}/favorite", ['favorite' => true])
            ->assertStatus(409);

        $this->actingAs($a, 'sanctum')->postJson("/api/friends/{$b->id}/request")->assertCreated();
        $this->actingAs($b, 'sanctum')->postJson("/api/friends/{$a->id}/accept")->assertOk();

        $this->actingAs($a, 'sanctum')->postJson("/api/friends/{$b->id}/favorite", ['favorite' => true])->assertOk();
        $this->assertTrue(Friendship::between($a->id, $b->id)->isFavoritedBy($a->id));
        $this->assertFalse(Friendship::between($a->id, $b->id)->isFavoritedBy($b->id));
    }

    public function test_cannot_accept_a_nonexistent_request(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $this->actingAs($b, 'sanctum')->postJson("/api/friends/{$a->id}/accept")->assertStatus(409);
    }

    public function test_friends_list_returns_accepted_with_favorite_flag(): void
    {
        $a = $this->makeUser(['name' => 'Aaron']);
        $b = $this->makeUser(['name' => 'Bea']);
        $this->actingAs($a, 'sanctum')->postJson("/api/friends/{$b->id}/request")->assertCreated();
        $this->actingAs($b, 'sanctum')->postJson("/api/friends/{$a->id}/accept")->assertOk();
        $this->actingAs($a, 'sanctum')->postJson("/api/friends/{$b->id}/favorite", ['favorite' => true])->assertOk();

        $res = $this->actingAs($a, 'sanctum')->getJson('/api/friends')->assertOk()->json();
        $this->assertCount(1, $res['friends']);
        $this->assertSame('Bea', $res['friends'][0]['user']['name']);
        $this->assertTrue($res['friends'][0]['favorited']);
        $this->assertArrayNotHasKey('email', $res['friends'][0]['user']); // no PII leak
    }
}
