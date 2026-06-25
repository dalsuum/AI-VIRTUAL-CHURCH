<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_probe_returns_200_with_null_user_when_logged_out(): void
    {
        $this->getJson('/api/auth/session')
            ->assertOk()
            ->assertJson(['authenticated' => false, 'user' => null]);
    }

    public function test_session_probe_returns_identity_when_authenticated(): void
    {
        $user = $this->makeUser(['email' => 'who@example.com']);

        $this->actingAs($user)->getJson('/api/auth/session')
            ->assertOk()
            ->assertJson(['authenticated' => true])
            ->assertJsonPath('user.email', 'who@example.com');
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }

    public function test_me_returns_entitlements_payload(): void
    {
        $user = $this->makeUser(['subscription_plan' => 'member', 'token_balance' => 100]);

        $this->actingAs($user)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.plan', 'member')
            ->assertJsonPath('user.token_balance', 100)
            ->assertJsonPath('user.is_premium', false)
            ->assertJsonStructure(['user' => [
                'id', 'name', 'email', 'role', 'permissions',
                'plan', 'subscription', 'token_balance', 'monthly_allowance', 'billing_enabled',
            ]]);
    }

    public function test_subscription_status_contract(): void
    {
        $user = $this->makeUser(['subscription_plan' => 'member', 'token_balance' => 100]);

        $this->actingAs($user)->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('plan', 'member')
            ->assertJsonPath('is_premium', false)
            ->assertJsonPath('monthly_allowance', 100)
            ->assertJsonStructure([
                'plan', 'status', 'expires_at', 'is_premium',
                'token_balance', 'monthly_allowance', 'max_pastors', 'shows_ads', 'billing_enabled',
            ]);
    }

    public function test_subscription_requires_authentication(): void
    {
        $this->getJson('/api/subscription')->assertStatus(401);
    }
}
