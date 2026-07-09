<?php

namespace Tests\Feature;

use App\Domains\Church\Models\Church;
use App\Domains\Church\Models\ChurchMembership;
use App\Domains\Groups\Models\Group;
use App\Domains\Groups\Models\GroupMembership;
use App\Domains\Invitations\Models\Invitation;
use App\Domains\Invitations\Services\InvitationService;
use App\Enums\ChurchRole;
use App\Enums\GroupRole;
use App\Enums\GroupType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupInvitationLinkTest extends TestCase
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

    private function mintLink(array $overrides = []): Invitation
    {
        return app(InvitationService::class)->sendLink(
            inviter: $this->leader,
            group: $this->group,
            maxUses: $overrides['max_uses'] ?? null,
            expiresAt: $overrides['expires_at'] ?? null,
        );
    }

    public function test_group_leader_mints_link_and_plain_member_cannot(): void
    {
        $res = $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/groups/{$this->group->id}/invitations", ['max_uses' => 10])
            ->assertCreated()->json();

        $this->assertSame('link', $res['kind']);
        $this->assertSame('group_membership', $res['activity']);
        $this->assertNull($res['invitee']);
        $this->assertSame(10, $res['max_uses']);
        $this->assertNotEmpty($res['token']);
        $this->assertStringContainsString($res['token'], $res['join_url']);

        $member = $this->makeUser();
        $this->churchMember($member, ChurchRole::MEMBER);
        $this->actingAs($member, 'sanctum')
            ->postJson("/api/groups/{$this->group->id}/invitations")
            ->assertForbidden();
    }

    public function test_redeeming_joins_group_and_enrolls_outsider_as_church_guest(): void
    {
        $link     = $this->mintLink();
        $outsider = $this->makeUser();

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/invitations/link/{$link->token}/redeem")
            ->assertOk()->assertJsonFragment(['group_id' => $this->group->id, 'role' => 'member']);

        $this->assertDatabaseHas('group_memberships', [
            'group_id' => $this->group->id, 'user_id' => $outsider->id, 'role' => 'member', 'status' => 'active',
        ]);
        $this->assertDatabaseHas('church_memberships', [
            'church_id' => $this->church->id, 'user_id' => $outsider->id, 'role' => 'guest', 'status' => 'active',
        ]);
        $this->assertDatabaseHas('invitations', ['id' => $link->id, 'use_count' => 1, 'status' => 'pending']);

        // A link-joined guest holds no church MEMBER role but must see their group.
        $this->assertTrue($outsider->fresh()->can('view', $this->group));
    }

    public function test_redeem_is_idempotent_for_an_active_member(): void
    {
        $link  = $this->mintLink(['max_uses' => 5]);
        $joiner = $this->makeUser();

        $this->actingAs($joiner, 'sanctum')->postJson("/api/invitations/link/{$link->token}/redeem")->assertOk();
        $this->actingAs($joiner, 'sanctum')->postJson("/api/invitations/link/{$link->token}/redeem")->assertOk();

        $this->assertSame(1, GroupMembership::where('group_id', $this->group->id)->where('user_id', $joiner->id)->count());
        $this->assertDatabaseHas('invitations', ['id' => $link->id, 'use_count' => 1]); // double tap costs nothing
    }

    public function test_max_uses_is_enforced(): void
    {
        $link = $this->mintLink(['max_uses' => 1]);
        $one  = $this->makeUser();
        $two  = $this->makeUser();

        $this->actingAs($one, 'sanctum')->postJson("/api/invitations/link/{$link->token}/redeem")->assertOk();
        $this->actingAs($two, 'sanctum')->postJson("/api/invitations/link/{$link->token}/redeem")->assertStatus(409);

        $this->assertDatabaseMissing('group_memberships', ['group_id' => $this->group->id, 'user_id' => $two->id]);
    }

    public function test_expired_link_cannot_be_redeemed_and_sweep_expires_it(): void
    {
        $link = $this->mintLink(['expires_at' => now()->subMinute()]);
        $late = $this->makeUser();

        $this->actingAs($late, 'sanctum')->postJson("/api/invitations/link/{$link->token}/redeem")->assertStatus(409);
        $this->assertDatabaseMissing('group_memberships', ['group_id' => $this->group->id, 'user_id' => $late->id]);

        // The existing sweep owns the durable flip and covers link invitations.
        app(InvitationService::class)->expireDue();
        $this->assertDatabaseHas('invitations', ['id' => $link->id, 'status' => 'expired']);
    }

    public function test_church_elder_can_revoke_a_link_they_did_not_create(): void
    {
        $link  = $this->mintLink();
        $elder = $this->makeUser();
        $this->churchMember($elder, ChurchRole::ELDER);

        $this->actingAs($elder, 'sanctum')
            ->postJson("/api/invitations/{$link->id}/cancel")
            ->assertOk()->assertJsonFragment(['status' => 'cancelled']);

        $joiner = $this->makeUser();
        $this->actingAs($joiner, 'sanctum')
            ->postJson("/api/invitations/link/{$link->token}/redeem")
            ->assertStatus(409);
    }

    public function test_plain_member_cannot_revoke_a_link(): void
    {
        $link   = $this->mintLink();
        $member = $this->makeUser();
        $this->churchMember($member, ChurchRole::MEMBER);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/invitations/{$link->id}/cancel")
            ->assertForbidden();
    }

    public function test_unknown_token_is_not_found(): void
    {
        $stranger = $this->makeUser();
        $this->actingAs($stranger, 'sanctum')
            ->postJson('/api/invitations/link/definitely-not-a-real-token/redeem')
            ->assertNotFound();
    }

    public function test_rejoin_reactivates_the_canonical_row_as_plain_member(): void
    {
        $link   = $this->mintLink();
        $former = $this->makeUser();
        // Left (or was removed from) the group while holding LEADER.
        GroupMembership::create([
            'group_id' => $this->group->id, 'user_id' => $former->id,
            'role' => GroupRole::LEADER, 'status' => 'inactive', 'joined_at' => now()->subYear(),
        ]);

        $this->actingAs($former, 'sanctum')
            ->postJson("/api/invitations/link/{$link->token}/redeem")
            ->assertOk()->assertJsonFragment(['role' => 'member']); // leadership does not survive a rejoin

        $this->assertSame(1, GroupMembership::where('group_id', $this->group->id)->where('user_id', $former->id)->count());
        $this->assertDatabaseHas('group_memberships', [
            'group_id' => $this->group->id, 'user_id' => $former->id, 'role' => 'member', 'status' => 'active',
        ]);
    }

    public function test_link_preview_shows_target_and_usability(): void
    {
        $link   = $this->mintLink(['max_uses' => 1]);
        $viewer = $this->makeUser();

        $this->actingAs($viewer, 'sanctum')->getJson("/api/invitations/link/{$link->token}")
            ->assertOk()
            ->assertJsonFragment(['usable' => true])
            ->assertJsonPath('group.name', 'Choir')
            ->assertJsonPath('church.name', 'Grace');

        $this->actingAs($viewer, 'sanctum')->postJson("/api/invitations/link/{$link->token}/redeem")->assertOk();

        // Exhausted: preview still resolves but reports unusable.
        $other = $this->makeUser();
        $this->actingAs($other, 'sanctum')->getJson("/api/invitations/link/{$link->token}")
            ->assertOk()->assertJsonFragment(['usable' => false]);
    }

    public function test_direct_invitations_cannot_use_the_membership_activity(): void
    {
        $friend = $this->makeUser();
        $this->actingAs($this->leader, 'sanctum')
            ->postJson('/api/invitations', ['invitee_id' => $friend->id, 'activity' => 'group_membership'])
            ->assertStatus(422);
    }
}
