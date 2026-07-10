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
