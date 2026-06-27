<?php

namespace Tests\Feature;

use App\Enums\FriendStatus;
use App\Domains\Friends\Models\Friendship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresencePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function befriend($a, $b): void
    {
        [$lo, $hi] = Friendship::orderedPair($a->id, $b->id);
        Friendship::create([
            'user_id' => $lo, 'friend_id' => $hi, 'status' => FriendStatus::ACCEPTED, 'requested_by' => $a->id,
        ]);
    }

    public function test_heartbeat_sets_online_and_me_reflects_it(): void
    {
        $u = $this->makeUser();
        $this->actingAs($u, 'sanctum')->postJson('/api/presence/heartbeat', ['activity' => 'reading'])
            ->assertNoContent();

        $this->actingAs($u, 'sanctum')->getJson('/api/presence/me')->assertOk()
            ->assertJsonFragment(['status' => 'online', 'activity' => 'reading']);
    }

    public function test_friend_sees_presence_but_stranger_gets_404(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $c = $this->makeUser();
        $this->befriend($a, $b);
        $this->actingAs($a, 'sanctum')->postJson('/api/presence/heartbeat')->assertNoContent();

        $this->actingAs($b, 'sanctum')->getJson("/api/presence/{$a->id}")->assertOk()
            ->assertJsonFragment(['status' => 'online']);

        // Default presence visibility is friends → a stranger can't see it (404, no probe).
        $this->actingAs($c, 'sanctum')->getJson("/api/presence/{$a->id}")->assertNotFound();
    }

    public function test_incognito_hides_presence_from_friends(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $this->befriend($a, $b);
        $this->actingAs($a, 'sanctum')->postJson('/api/presence/heartbeat')->assertNoContent();
        $this->actingAs($a, 'sanctum')->putJson('/api/me/privacy', ['incognito' => true])->assertOk();

        $this->actingAs($b, 'sanctum')->getJson("/api/presence/{$a->id}")->assertNotFound();
        // The user still sees their own presence.
        $this->actingAs($a, 'sanctum')->getJson('/api/presence/me')->assertOk()
            ->assertJsonFragment(['status' => 'online']);
    }

    public function test_friends_presence_lists_only_visible_friends(): void
    {
        $me = $this->makeUser();
        $f1 = $this->makeUser();
        $f2 = $this->makeUser();
        $this->befriend($me, $f1);
        $this->befriend($me, $f2);
        $this->actingAs($f1, 'sanctum')->postJson('/api/presence/heartbeat')->assertNoContent();
        $this->actingAs($f2, 'sanctum')->putJson('/api/me/privacy', ['incognito' => true])->assertOk();

        $res = $this->actingAs($me, 'sanctum')->getJson('/api/presence/friends')->assertOk()->json('presence');
        $this->assertArrayHasKey($f1->id, $res);     // visible
        $this->assertArrayNotHasKey($f2->id, $res);   // incognito → filtered out
    }

    public function test_privacy_defaults_and_update_roundtrip(): void
    {
        $u = $this->makeUser();

        $this->actingAs($u, 'sanctum')->getJson('/api/me/privacy')->assertOk()
            ->assertJsonFragment(['profile_visibility' => 'friends', 'incognito' => false]);

        $this->actingAs($u, 'sanctum')->putJson('/api/me/privacy', [
            'profile_visibility' => 'private', 'friend_only_mode' => true,
        ])->assertOk()->assertJsonFragment(['profile_visibility' => 'private', 'friend_only_mode' => true]);

        $this->assertDatabaseHas('privacy_settings', [
            'user_id' => $u->id, 'profile_visibility' => 'private', 'friend_only_mode' => true,
        ]);
    }
}
