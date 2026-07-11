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

class GroupAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function churchMember(User $u, Church $c, ChurchRole $role): void
    {
        ChurchMembership::create([
            'church_id' => $c->id, 'user_id' => $u->id, 'role' => $role,
            'status' => ChurchMembership::STATUS_ACTIVE, 'joined_at' => now(),
        ]);
    }

    private function groupMember(User $u, Group $g, GroupRole $role, string $status = GroupMembership::STATUS_ACTIVE): void
    {
        GroupMembership::create([
            'group_id' => $g->id, 'user_id' => $u->id, 'role' => $role,
            'status' => $status, 'joined_at' => now(),
        ]);
    }

    private function church(): Church
    {
        return Church::create(['name' => 'Grace', 'slug' => 'grace']);
    }

    public function test_group_leadership_is_an_explicit_appointment(): void
    {
        $church = $this->church();
        $choir  = Group::create(['church_id' => $church->id, 'name' => 'Choir', 'type' => GroupType::CHOIR]);
        $leader = $this->makeUser();
        $member = $this->makeUser();
        $this->churchMember($leader, $church, ChurchRole::MEMBER);
        $this->churchMember($member, $church, ChurchRole::MEMBER);
        $this->groupMember($leader, $choir, GroupRole::LEADER);
        $this->groupMember($member, $choir, GroupRole::MEMBER);

        $put = fn ($actor, $userId, $role) => $this->actingAs($actor, 'sanctum')
            ->putJson("/api/groups/{$choir->id}/members/{$userId}/role", ['role' => $role]);

        // A plain member cannot appoint; the group's leader can (co-leader), and
        // can demote them back. Nobody changes their own role. Church elders+
        // can appoint without any group membership row.
        $put($member, $member->id, 'leader')->assertForbidden();
        $put($leader, $member->id, 'leader')->assertOk()->assertJsonFragment(['role' => 'leader']);
        $put($leader, $member->id, 'member')->assertOk();
        $put($leader, $leader->id, 'member')->assertForbidden();

        $elder = $this->makeUser();
        $this->churchMember($elder, $church, ChurchRole::ELDER);
        $put($elder, $member->id, 'leader')->assertOk();

        // Only active memberships can be appointed; unknown roles are rejected.
        $outsider = $this->makeUser();
        $put($elder, $outsider->id, 'leader')->assertNotFound();
        $put($elder, $member->id, 'owner')->assertStatus(422);
    }

    public function test_group_leadership_is_scoped_to_the_group(): void
    {
        // The design decision under test: "worship leader" = LEADER on the worship
        // group, not a ChurchRole — so authority must not leak into other groups.
        $church = $this->church();
        $choir  = Group::create(['church_id' => $church->id, 'name' => 'Choir', 'type' => GroupType::CHOIR]);
        $study  = Group::create(['church_id' => $church->id, 'name' => 'Bible Study', 'type' => GroupType::BIBLE_STUDY]);

        $choirLeader = $this->makeUser();
        $this->churchMember($choirLeader, $church, ChurchRole::MEMBER);
        $this->groupMember($choirLeader, $choir, GroupRole::LEADER);
        $this->groupMember($choirLeader, $study, GroupRole::MEMBER);

        $this->assertTrue($choirLeader->can('manage', $choir));
        $this->assertFalse($choirLeader->can('manage', $study));
    }

    public function test_church_elders_oversee_all_groups_without_group_membership(): void
    {
        $church = $this->church();
        $choir  = Group::create(['church_id' => $church->id, 'name' => 'Choir', 'type' => GroupType::CHOIR]);

        $elder  = $this->makeUser();
        $deacon = $this->makeUser();
        $this->churchMember($elder, $church, ChurchRole::ELDER);
        $this->churchMember($deacon, $church, ChurchRole::DEACON);

        $this->assertTrue($elder->can('manage', $choir));   // no group_memberships row needed
        $this->assertFalse($deacon->can('manage', $choir)); // below the elder threshold
    }

    public function test_groups_are_visible_church_wide_but_not_to_outsiders(): void
    {
        $church = $this->church();
        $prayer = Group::create(['church_id' => $church->id, 'name' => 'Prayer', 'type' => GroupType::PRAYER]);

        $member   = $this->makeUser();
        $outsider = $this->makeUser();
        $this->churchMember($member, $church, ChurchRole::MEMBER);

        $this->assertTrue($member->can('view', $prayer));    // church member, not a group member
        $this->assertFalse($outsider->can('view', $prayer));
    }

    public function test_deleting_a_group_is_church_governance_not_group_leadership(): void
    {
        $church = $this->church();
        $youth  = Group::create(['church_id' => $church->id, 'name' => 'Youth', 'type' => GroupType::YOUTH]);

        $leader = $this->makeUser();
        $elder  = $this->makeUser();
        $this->churchMember($leader, $church, ChurchRole::MEMBER);
        $this->groupMember($leader, $youth, GroupRole::LEADER);
        $this->churchMember($elder, $church, ChurchRole::ELDER);

        $this->assertFalse($leader->can('delete', $youth));
        $this->assertTrue($elder->can('delete', $youth));
    }

    public function test_creating_groups_requires_church_leader(): void
    {
        $church = $this->church();
        $member = $this->makeUser();
        $leader = $this->makeUser();
        $this->churchMember($member, $church, ChurchRole::MEMBER);
        $this->churchMember($leader, $church, ChurchRole::LEADER);

        $this->assertFalse($member->can('create', [Group::class, $church]));
        $this->assertTrue($leader->can('create', [Group::class, $church]));
    }

    public function test_inactive_membership_confers_no_group_role(): void
    {
        $church = $this->church();
        $choir  = Group::create(['church_id' => $church->id, 'name' => 'Choir', 'type' => GroupType::CHOIR]);

        $former = $this->makeUser();
        $this->churchMember($former, $church, ChurchRole::MEMBER);
        $this->groupMember($former, $choir, GroupRole::LEADER, 'inactive');

        $this->assertNull($former->groupRole($choir->id));
        $this->assertFalse($former->can('manage', $choir));
    }

    public function test_one_membership_per_group_is_enforced(): void
    {
        $church = $this->church();
        $choir  = Group::create(['church_id' => $church->id, 'name' => 'Choir', 'type' => GroupType::CHOIR]);
        $u = $this->makeUser();
        $this->groupMember($u, $choir, GroupRole::MEMBER);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->groupMember($u, $choir, GroupRole::LEADER);
    }

    public function test_members_list_church_groups_with_their_own_context(): void
    {
        $church = $this->church();
        $choir  = Group::create(['church_id' => $church->id, 'name' => 'Choir', 'type' => GroupType::CHOIR]);
        Group::create(['church_id' => $church->id, 'name' => 'Youth', 'type' => GroupType::YOUTH]);

        $member = $this->makeUser();
        $this->churchMember($member, $church, ChurchRole::MEMBER);
        $this->groupMember($member, $choir, GroupRole::LEADER);

        $res = $this->actingAs($member, 'sanctum')->getJson("/api/churches/{$church->id}/groups")
            ->assertOk()->json('groups');

        $this->assertCount(2, $res);
        $mine = collect($res)->firstWhere('name', 'Choir');
        $this->assertSame(1, $mine['member_count']);
        $this->assertSame('leader', $mine['my_role']);
        $this->assertNull($mine['open_session']);
        $this->assertNull(collect($res)->firstWhere('name', 'Youth')['my_role']);

        $outsider = $this->makeUser();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/churches/{$church->id}/groups")->assertForbidden();
    }

    public function test_group_page_show_gates_manager_extras(): void
    {
        $church = $this->church();
        $choir  = Group::create(['church_id' => $church->id, 'name' => 'Choir', 'type' => GroupType::CHOIR]);

        $leader = $this->makeUser();
        $member = $this->makeUser();
        $this->churchMember($leader, $church, ChurchRole::MEMBER);
        $this->churchMember($member, $church, ChurchRole::MEMBER);
        $this->groupMember($leader, $choir, GroupRole::LEADER);
        $this->groupMember($member, $choir, GroupRole::MEMBER);

        // Managers see counts + links; the payload names the leaders.
        $res = $this->actingAs($leader, 'sanctum')->getJson("/api/groups/{$choir->id}")
            ->assertOk()->json();
        $this->assertTrue($res['can_manage']);
        $this->assertSame(2, $res['member_count']);
        $this->assertContains($leader->name, $res['leaders']);
        $this->assertArrayHasKey('pending_request_count', $res);
        $this->assertArrayHasKey('links', $res);

        // Plain members get the header/status data but no manager extras.
        $res = $this->actingAs($member, 'sanctum')->getJson("/api/groups/{$choir->id}")
            ->assertOk()->json();
        $this->assertFalse($res['can_manage']);
        $this->assertSame('member', $res['my_role']);
        $this->assertArrayNotHasKey('pending_request_count', $res);
        $this->assertArrayNotHasKey('links', $res);

        // Outsiders see nothing at all.
        $outsider = $this->makeUser();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/groups/{$choir->id}")->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/groups/{$choir->id}/members")->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/groups/{$choir->id}/activity")->assertForbidden();
    }

    public function test_group_activity_projects_existing_rows_newest_first(): void
    {
        $church = $this->church();
        $choir  = Group::create(['church_id' => $church->id, 'name' => 'Choir', 'type' => GroupType::CHOIR]);

        $leader = $this->makeUser();
        $this->churchMember($leader, $church, ChurchRole::MEMBER);
        GroupMembership::create([
            'group_id' => $choir->id, 'user_id' => $leader->id, 'role' => GroupRole::LEADER,
            'status' => GroupMembership::STATUS_ACTIVE, 'joined_at' => now()->subDay(),
        ]);
        // A link minted later than the join must sort first.
        $this->actingAs($leader, 'sanctum')
            ->postJson("/api/groups/{$choir->id}/invitations")->assertCreated();

        $items = $this->actingAs($leader, 'sanctum')->getJson("/api/groups/{$choir->id}/activity")
            ->assertOk()->json('activity');

        $this->assertSame('link_created', $items[0]['type']);
        $this->assertSame($leader->name, $items[0]['actor']);
        $this->assertSame('member_joined', $items[1]['type']);
    }

    public function test_group_study_room_share_read_ask_and_owner_controls(): void
    {
        $church = $this->church();
        $choir  = Group::create(['church_id' => $church->id, 'name' => 'Choir', 'type' => GroupType::CHOIR]);
        $leader = $this->makeUser();
        $member = $this->makeUser();
        $this->churchMember($leader, $church, ChurchRole::MEMBER);
        $this->churchMember($member, $church, ChurchRole::MEMBER);
        $this->groupMember($leader, $choir, GroupRole::LEADER);
        $this->groupMember($member, $choir, GroupRole::MEMBER);

        $study = \App\Models\StudySession::create([
            'user_id' => $leader->id, 'language' => 'en', 'translation' => 'kjv',
            'style' => 'discussion', 'topic' => 'John 15',
            'state' => 'discussing', 'agent_count' => 2,
            'stream_token' => hash('sha256', 'test-token'),
        ]);

        // Only the owner-manager attaches; a member cannot.
        $this->actingAs($member, 'sanctum')
            ->postJson("/api/groups/{$choir->id}/study", ['study_session_id' => $study->id])
            ->assertForbidden();
        $this->actingAs($leader, 'sanctum')
            ->postJson("/api/groups/{$choir->id}/study", ['study_session_id' => $study->id])
            ->assertOk()->assertJsonPath('study.topic', 'John 15');

        // A member reads the room and asks in it; the message records the sender.
        $this->actingAs($member, 'sanctum')->getJson("/api/v1/study/sessions/{$study->id}")->assertOk();
        // Outsiders see nothing; and creator-only controls stay closed to members.
        $outsider = $this->makeUser();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/v1/study/sessions/{$study->id}")->assertForbidden();
        $this->actingAs($member, 'sanctum')->postJson("/api/v1/study/sessions/{$study->id}/end")->assertForbidden();

        // Detach closes the room for members again.
        $this->actingAs($leader, 'sanctum')->deleteJson("/api/groups/{$choir->id}/study")->assertOk();
        $this->actingAs($member, 'sanctum')->getJson("/api/v1/study/sessions/{$study->id}")->assertForbidden();
    }

    public function test_group_service_share_and_member_playback(): void
    {
        $church = $this->church();
        $choir  = Group::create(['church_id' => $church->id, 'name' => 'Choir', 'type' => GroupType::CHOIR]);
        $leader = $this->makeUser();
        $member = $this->makeUser();
        $this->churchMember($leader, $church, ChurchRole::MEMBER);
        $this->churchMember($member, $church, ChurchRole::MEMBER);
        $this->groupMember($leader, $choir, GroupRole::LEADER);
        $this->groupMember($member, $choir, GroupRole::MEMBER);

        $service = \App\Models\ServiceSession::create([
            'user_id' => $leader->id, 'session_token' => str_repeat('a', 64),
            'status' => 'completed', 'language' => 'en', 'music_source' => 'suno',
        ]);

        // Members cannot share; a manager can share only THEIR OWN service.
        $this->actingAs($member, 'sanctum')
            ->postJson("/api/groups/{$choir->id}/service", ['session_token' => $service->session_token])
            ->assertForbidden();
        $other = $this->makeUser();
        $this->churchMember($other, $church, ChurchRole::ELDER);
        $this->actingAs($other, 'sanctum')
            ->postJson("/api/groups/{$choir->id}/service", ['session_token' => $service->session_token])
            ->assertNotFound();   // elder may manage, but it isn't their service

        $this->actingAs($leader, 'sanctum')
            ->postJson("/api/groups/{$choir->id}/service", ['session_token' => $service->session_token])
            ->assertOk()->assertJsonPath('service.shared_by', $leader->name);

        // Group members see the card and can OPEN the service by membership alone.
        $this->actingAs($member, 'sanctum')->getJson("/api/groups/{$choir->id}/service")
            ->assertOk()->assertJsonPath('service.session_token', $service->session_token);
        $this->actingAs($member, 'sanctum')->getJson("/api/service/{$service->session_token}")
            ->assertOk();

        // Outsiders get a 404, not a leak.
        $outsider = $this->makeUser();
        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/service/{$service->session_token}")->assertNotFound();

        // Unshare: card empties and member playback closes.
        $this->actingAs($leader, 'sanctum')->deleteJson("/api/groups/{$choir->id}/service")->assertOk();
        $this->actingAs($member, 'sanctum')->getJson("/api/groups/{$choir->id}/service")
            ->assertOk()->assertJsonPath('service', null);
        $this->actingAs($member, 'sanctum')
            ->getJson("/api/service/{$service->session_token}")->assertNotFound();
    }

    public function test_church_leaders_create_groups_over_http(): void
    {
        $church = $this->church();
        $leader = $this->makeUser();
        $member = $this->makeUser();
        $this->churchMember($leader, $church, ChurchRole::LEADER);
        $this->churchMember($member, $church, ChurchRole::MEMBER);

        $this->actingAs($leader, 'sanctum')
            ->postJson("/api/churches/{$church->id}/groups", ['name' => 'Prayer Warriors', 'type' => 'prayer'])
            ->assertCreated()->assertJsonFragment(['name' => 'Prayer Warriors', 'type' => 'prayer']);

        // Duplicate name within the church is a validation error, not a 500.
        $this->actingAs($leader, 'sanctum')
            ->postJson("/api/churches/{$church->id}/groups", ['name' => 'Prayer Warriors', 'type' => 'prayer'])
            ->assertStatus(422);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/churches/{$church->id}/groups", ['name' => 'Another', 'type' => 'custom'])
            ->assertForbidden();
    }
}
