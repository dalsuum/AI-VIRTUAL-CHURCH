<?php

namespace Tests\Feature;

use App\Domains\Church\Models\Church;
use App\Domains\Church\Models\ChurchMembership;
use App\Domains\Groups\Models\Group;
use App\Domains\Groups\Models\GroupMembership;
use App\Enums\ChurchRole;
use App\Enums\GroupRole;
use App\Enums\GroupType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChurchAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function member(User $u, Church $c, ChurchRole $role): void
    {
        ChurchMembership::create([
            'church_id' => $c->id, 'user_id' => $u->id, 'role' => $role,
            'status' => ChurchMembership::STATUS_ACTIVE, 'joined_at' => now(),
        ]);
    }

    public function test_role_thresholds_are_owned_by_the_enum(): void
    {
        $church = Church::create(['name' => 'Grace', 'slug' => 'grace']);
        $leader = $this->makeUser();
        $elder  = $this->makeUser();
        $this->member($leader, $church, ChurchRole::LEADER);
        $this->member($elder, $church, ChurchRole::ELDER);

        // Leader: can create sessions, cannot manage; Elder: can do both.
        $this->assertTrue($leader->can('createSession', $church));
        $this->assertFalse($leader->can('manage', $church));
        $this->assertTrue($elder->can('createSession', $church)); // higher role satisfies lower threshold
        $this->assertTrue($elder->can('manage', $church));
    }

    public function test_non_member_cannot_view_roster(): void
    {
        $church  = Church::create(['name' => 'Grace', 'slug' => 'grace']);
        $member  = $this->makeUser();
        $outsider = $this->makeUser();
        $this->member($member, $church, ChurchRole::MEMBER);

        $this->actingAs($member, 'sanctum')->getJson("/api/churches/{$church->id}/members")->assertOk();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/churches/{$church->id}/members")->assertForbidden();
    }

    public function test_index_lists_my_active_churches_with_role(): void
    {
        $church = Church::create(['name' => 'Grace', 'slug' => 'grace']);
        $u = $this->makeUser();
        $this->member($u, $church, ChurchRole::PASTOR);

        $this->actingAs($u, 'sanctum')->getJson('/api/churches')->assertOk()
            ->assertJsonFragment(['id' => $church->id, 'name' => 'Grace', 'role' => 'pastor']);
    }

    public function test_directory_carries_groups_and_activity_is_curated(): void
    {
        $church = Church::create(['name' => 'Grace', 'slug' => 'grace']);
        $choir  = Group::create(['church_id' => $church->id, 'name' => 'Choir', 'type' => GroupType::CHOIR]);
        $alice  = $this->makeUser();
        $this->member($alice, $church, ChurchRole::MEMBER);
        GroupMembership::create([
            'group_id' => $choir->id, 'user_id' => $alice->id, 'role' => GroupRole::MEMBER,
            'status' => GroupMembership::STATUS_ACTIVE, 'joined_at' => now()->addMinute(),
        ]);

        // Directory: each member row carries their group names — no follow-up calls.
        $members = $this->actingAs($alice, 'sanctum')->getJson("/api/churches/{$church->id}/members")
            ->assertOk()->json('members');
        $mine = collect($members)->firstWhere('id', $alice->id);
        $this->assertSame(['Choir'], $mine['groups']);
        $this->assertSame('active', $mine['status']);
        $this->assertNotNull($mine['joined_at']);

        // Feed: curated types only, newest first; the group join outranks the church join.
        $items = $this->actingAs($alice, 'sanctum')->getJson("/api/churches/{$church->id}/activity")
            ->assertOk()->json('activity');
        $types = array_column($items, 'type');
        $this->assertContains('member_joined_group', $types);
        $this->assertContains('member_joined_church', $types);
        $this->assertContains('group_created', $types);
        $this->assertSame('member_joined_group', $items[0]['type']);
        $this->assertSame($alice->name, $items[0]['actor']);
        $this->assertSame('Choir', $items[0]['subject']);

        $outsider = $this->makeUser();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/churches/{$church->id}/activity")->assertForbidden();
    }

    public function test_guests_see_profile_and_groups_but_not_directory_or_feed(): void
    {
        // The link-joined guest boundary (acceptance finding, owner decision):
        // profile + ministry catalog = yes; member names (roster, feed) = no.
        $church = Church::create(['name' => 'Grace', 'slug' => 'grace']);
        Group::create(['church_id' => $church->id, 'name' => 'Choir', 'type' => GroupType::CHOIR]);
        $guest = $this->makeUser();
        $this->member($guest, $church, ChurchRole::GUEST);

        $this->actingAs($guest, 'sanctum')->getJson("/api/churches/{$church->id}")
            ->assertOk()->assertJsonFragment(['name' => 'Grace']);
        $this->actingAs($guest, 'sanctum')->getJson("/api/churches/{$church->id}/groups")
            ->assertOk()->assertJsonCount(1, 'groups');

        $this->actingAs($guest, 'sanctum')->getJson("/api/churches/{$church->id}/members")->assertForbidden();
        $this->actingAs($guest, 'sanctum')->getJson("/api/churches/{$church->id}/activity")->assertForbidden();

        // Outsiders (no membership row at all) still see nothing.
        $outsider = $this->makeUser();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/churches/{$church->id}")->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/churches/{$church->id}/groups")->assertForbidden();
    }

    public function test_church_role_changes_follow_strict_dominance(): void
    {
        $church = Church::create(['name' => 'Grace', 'slug' => 'grace']);
        $owner  = $this->makeUser();
        $pastor = $this->makeUser();
        $elder  = $this->makeUser();
        $target = $this->makeUser();
        $this->member($owner, $church, ChurchRole::OWNER);
        $this->member($pastor, $church, ChurchRole::PASTOR);
        $this->member($elder, $church, ChurchRole::ELDER);
        $this->member($target, $church, ChurchRole::MEMBER);

        $put = fn ($actor, $userId, $role) => $this->actingAs($actor, 'sanctum')
            ->putJson("/api/churches/{$church->id}/members/{$userId}/role", ['role' => $role]);

        // An elder manages strictly below: member → deacon works…
        $put($elder, $target->id, 'deacon')->assertOk()->assertJsonFragment(['role' => 'deacon']);
        // …but cannot mint an equal (elder) or a superior (pastor).
        $put($elder, $target->id, 'elder')->assertForbidden();
        $put($elder, $target->id, 'pastor')->assertForbidden();
        // Targets at-or-above the actor are untouchable — a pastor cannot demote the owner.
        $put($pastor, $owner->id, 'member')->assertForbidden();
        // Nobody changes their own role; owner is never assignable through this flow.
        $put($owner, $owner->id, 'pastor')->assertForbidden();
        $put($owner, $target->id, 'owner')->assertStatus(422);
        // Only the owner can appoint a pastor.
        $put($owner, $target->id, 'pastor')->assertOk();
        // Below the manage threshold you can't enter at all.
        $deacon = $this->makeUser();
        $this->member($deacon, $church, ChurchRole::DEACON);
        $put($deacon, $elder->id, 'member')->assertForbidden();
    }
}
