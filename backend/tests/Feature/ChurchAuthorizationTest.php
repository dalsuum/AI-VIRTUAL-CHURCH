<?php

namespace Tests\Feature;

use App\Domains\Church\Models\Church;
use App\Domains\Church\Models\ChurchMembership;
use App\Enums\ChurchRole;
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
}
