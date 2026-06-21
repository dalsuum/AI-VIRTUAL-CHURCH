<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_route_blocks_unauthenticated_requests(): void
    {
        $this->postJson('/api/admin/users', [])->assertStatus(401);
    }

    public function test_admin_route_blocks_a_plain_member(): void
    {
        $member = $this->makeUser(['role' => User::ROLE_MEMBER]);

        $this->actingAs($member)
            ->postJson('/api/admin/users', [
                'name' => 'X', 'email' => 'x@example.com', 'role' => 'member',
            ])
            ->assertStatus(403);
    }

    public function test_admin_route_blocks_a_guest_account(): void
    {
        $guest = $this->makeUser(['email' => 'walkup@guest.local', 'role' => User::ROLE_GUEST]);

        $this->actingAs($guest)->postJson('/api/admin/users', [])->assertStatus(403);
    }

    public function test_admin_can_reach_admin_routes(): void
    {
        $admin = $this->makeUser(['role' => User::ROLE_ADMIN, 'is_admin' => true]);

        // 201 (created), not 403 — authorization passed.
        $this->actingAs($admin)
            ->postJson('/api/admin/users', [
                'name' => 'New Staff', 'email' => 'staff@example.com', 'role' => 'presenter',
            ])
            ->assertStatus(201);
    }

    public function test_member_can_read_their_own_subscription(): void
    {
        $member = $this->makeUser(['role' => User::ROLE_MEMBER]);

        $this->actingAs($member)->getJson('/api/subscription')->assertOk();
    }
}
