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

    public function test_session_probe_does_not_consume_login_quota(): void
    {
        $this->withMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        // Hammer the read-only probe well past the credential bucket (5/min)...
        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/api/auth/session')->assertOk();
        }

        // ...and login is still reachable (422 invalid creds, not 429 throttled).
        $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'x'])
            ->assertStatus(422);
    }

    public function test_login_is_rate_limited_per_identifier(): void
    {
        $this->withMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $payload = ['email' => 'target@example.com', 'password' => 'wrong'];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', $payload)->assertStatus(422);
        }

        $this->postJson('/api/login', $payload)->assertStatus(429);
    }

    public function test_login_buckets_are_independent_per_email(): void
    {
        $this->withMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $exhaust = ['email' => 'a@example.com', 'password' => 'wrong'];
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/login', $exhaust);
        }
        $this->postJson('/api/login', $exhaust)->assertStatus(429);

        // A different account from the same client is unaffected.
        $this->postJson('/api/login', ['email' => 'b@example.com', 'password' => 'wrong'])
            ->assertStatus(422);
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
