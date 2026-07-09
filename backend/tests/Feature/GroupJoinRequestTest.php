<?php

namespace Tests\Feature;

use App\Domains\Church\Models\Church;
use App\Domains\Church\Models\ChurchMembership;
use App\Domains\Groups\Models\Group;
use App\Domains\Groups\Models\GroupMembership;
use App\Domains\Invitations\Notifications\InvitationReceivedNotification;
use App\Domains\Invitations\Notifications\InvitationResponseNotification;
use App\Enums\ChurchRole;
use App\Enums\GroupRole;
use App\Enums\GroupType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupJoinRequestTest extends TestCase
{
    use RefreshDatabase;

    private Church $church;
    private Group $group;
    private User $leader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->church = Church::create(['name' => 'Grace', 'slug' => 'grace']);
        $this->group  = Group::create(['church_id' => $this->church->id, 'name' => 'Choir', 'type' => GroupType::CHOIR]);
        $this->leader = $this->makeUser();
        $this->churchMember($this->leader, ChurchRole::MEMBER);
        GroupMembership::create([
            'group_id' => $this->group->id, 'user_id' => $this->leader->id,
            'role' => GroupRole::LEADER, 'status' => GroupMembership::STATUS_ACTIVE, 'joined_at' => now(),
        ]);
    }

    private function churchMember(User $u, ChurchRole $role): void
    {
        ChurchMembership::create([
            'church_id' => $this->church->id, 'user_id' => $u->id, 'role' => $role,
            'status' => ChurchMembership::STATUS_ACTIVE, 'joined_at' => now(),
        ]);
    }

    /** A church member files a request; returns the invitation id. */
    private function request(User $u): string
    {
        return $this->actingAs($u, 'sanctum')
            ->postJson("/api/groups/{$this->group->id}/join-requests")
            ->assertCreated()->json('id');
    }

    public function test_church_member_requests_and_asking_twice_is_one_request(): void
    {
        $member = $this->makeUser();
        $this->churchMember($member, ChurchRole::MEMBER);

        $res = $this->actingAs($member, 'sanctum')
            ->postJson("/api/groups/{$this->group->id}/join-requests", ['message' => 'I sing tenor'])
            ->assertCreated()->json();

        $this->assertSame('request', $res['kind']);
        $this->assertSame('pending', $res['status']);
        $this->assertSame('Choir', $res['group']['name']);
        $this->assertNull($res['invitee']);

        // Idempotent: the open request is returned, not duplicated.
        $again = $this->actingAs($member, 'sanctum')
            ->postJson("/api/groups/{$this->group->id}/join-requests")
            ->assertCreated()->json('id');
        $this->assertSame($res['id'], $again);
        $this->assertSame(1, \App\Domains\Invitations\Models\Invitation::where('inviter_id', $member->id)->count());

        // The group's leader was notified that someone asked to join.
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->leader->id,
            'type'          => InvitationReceivedNotification::class,
        ]);
    }

    public function test_outsider_cannot_request_a_group_they_cannot_see(): void
    {
        $outsider = $this->makeUser();
        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/groups/{$this->group->id}/join-requests")
            ->assertForbidden();
    }

    public function test_active_member_cannot_request(): void
    {
        $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/groups/{$this->group->id}/join-requests")
            ->assertStatus(409);
    }

    public function test_leader_approves_and_membership_plus_audit_land_together(): void
    {
        $member = $this->makeUser();
        $this->churchMember($member, ChurchRole::MEMBER);
        $id = $this->request($member);

        $this->actingAs($this->leader, 'sanctum')->postJson("/api/invitations/{$id}/accept")
            ->assertOk()->assertJsonFragment(['status' => 'accepted']);

        $this->assertDatabaseHas('group_memberships', [
            'group_id' => $this->group->id, 'user_id' => $member->id, 'role' => 'member', 'status' => 'active',
        ]);
        $this->assertDatabaseHas('invitations', ['id' => $id, 'responded_by' => $this->leader->id]);

        // The requester (= inviter in this reversed flow) is told their request was approved.
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $member->id,
            'type'          => InvitationResponseNotification::class,
        ]);
    }

    public function test_church_elder_can_approve_without_group_membership(): void
    {
        $member = $this->makeUser();
        $this->churchMember($member, ChurchRole::MEMBER);
        $id = $this->request($member);

        $elder = $this->makeUser();
        $this->churchMember($elder, ChurchRole::ELDER);

        $this->actingAs($elder, 'sanctum')->postJson("/api/invitations/{$id}/accept")->assertOk();
        $this->assertDatabaseHas('group_memberships', [
            'group_id' => $this->group->id, 'user_id' => $member->id, 'status' => 'active',
        ]);
    }

    public function test_neither_requester_nor_plain_member_can_approve(): void
    {
        $member = $this->makeUser();
        $this->churchMember($member, ChurchRole::MEMBER);
        $id = $this->request($member);

        // The requester cannot approve their own request…
        $this->actingAs($member, 'sanctum')->postJson("/api/invitations/{$id}/accept")->assertForbidden();

        // …nor can an uninvolved church member.
        $bystander = $this->makeUser();
        $this->churchMember($bystander, ChurchRole::MEMBER);
        $this->actingAs($bystander, 'sanctum')->postJson("/api/invitations/{$id}/accept")->assertForbidden();
    }

    public function test_leader_declines_and_no_membership_is_created(): void
    {
        $member = $this->makeUser();
        $this->churchMember($member, ChurchRole::MEMBER);
        $id = $this->request($member);

        $this->actingAs($this->leader, 'sanctum')->postJson("/api/invitations/{$id}/decline")
            ->assertOk()->assertJsonFragment(['status' => 'declined']);

        $this->assertDatabaseMissing('group_memberships', ['group_id' => $this->group->id, 'user_id' => $member->id]);
        $this->assertDatabaseHas('invitations', ['id' => $id, 'responded_by' => $this->leader->id]);
    }

    public function test_requester_withdraws_through_the_ordinary_cancel(): void
    {
        $member = $this->makeUser();
        $this->churchMember($member, ChurchRole::MEMBER);
        $id = $this->request($member);

        $this->actingAs($member, 'sanctum')->postJson("/api/invitations/{$id}/cancel")
            ->assertOk()->assertJsonFragment(['status' => 'cancelled']);
    }

    public function test_managers_list_pending_requests_and_members_cannot(): void
    {
        $member = $this->makeUser();
        $this->churchMember($member, ChurchRole::MEMBER);
        $this->request($member);

        $this->actingAs($this->leader, 'sanctum')->getJson("/api/groups/{$this->group->id}/join-requests")
            ->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.inviter.name', $member->name);

        $this->actingAs($member, 'sanctum')->getJson("/api/groups/{$this->group->id}/join-requests")
            ->assertForbidden();
    }

    public function test_approving_after_the_requester_joined_via_link_is_a_noop(): void
    {
        $member = $this->makeUser();
        $this->churchMember($member, ChurchRole::MEMBER);
        $id = $this->request($member);

        // While the request sat pending, they joined through a link.
        GroupMembership::create([
            'group_id' => $this->group->id, 'user_id' => $member->id,
            'role' => GroupRole::MEMBER, 'status' => GroupMembership::STATUS_ACTIVE, 'joined_at' => now(),
        ]);

        $this->actingAs($this->leader, 'sanctum')->postJson("/api/invitations/{$id}/accept")->assertOk();
        $this->assertSame(1, GroupMembership::where('group_id', $this->group->id)->where('user_id', $member->id)->count());
    }
}
