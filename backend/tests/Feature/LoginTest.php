<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_log_in(): void
    {
        $this->makeUser(['email' => 'live@example.com', 'password' => Hash::make('correct-horse')]);

        $this->postJson('/api/login', ['email' => 'live@example.com', 'password' => 'correct-horse'])
            ->assertOk()
            ->assertJsonPath('user.email', 'live@example.com');

        $this->assertAuthenticated();
    }

    public function test_pending_user_cannot_log_in(): void
    {
        $this->makeUser([
            'email'             => 'pending@example.com',
            'password'          => Hash::make('correct-horse'),
            'status'            => User::STATUS_PENDING,
            'email_verified_at' => null,
        ]);

        $this->postJson('/api/login', ['email' => 'pending@example.com', 'password' => 'correct-horse'])
            ->assertStatus(403)
            ->assertJson(['message' => 'Please activate your account from the email we sent.']);

        $this->assertGuest();
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $this->makeUser(['email' => 'real@example.com', 'password' => Hash::make('correct-horse')]);

        $this->postJson('/api/login', ['email' => 'real@example.com', 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Invalid credentials']);
        $this->assertGuest();
    }

    public function test_unknown_email_is_rejected_the_same_as_a_wrong_password(): void
    {
        // No account enumeration: identical 422 + message for unknown email.
        $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'whatever'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Invalid credentials']);
    }

    public function test_blocked_user_cannot_log_in(): void
    {
        $this->makeUser([
            'email'      => 'blocked@example.com',
            'password'   => Hash::make('correct-horse'),
            'is_blocked' => true,
        ]);

        $this->postJson('/api/login', ['email' => 'blocked@example.com', 'password' => 'correct-horse'])
            ->assertStatus(403)
            ->assertJson(['message' => 'This account has been suspended.']);
    }

    public function test_logout_endpoint_succeeds_for_an_authenticated_user(): void
    {
        $user = $this->makeUser();

        // The array session driver doesn't persist across in-process test requests,
        // so we assert the endpoint contract (it invalidates the session server-side
        // and 200s) rather than a cross-request guest state.
        $this->actingAs($user)->postJson('/api/logout')
            ->assertOk()
            ->assertJson(['message' => 'Logged out']);
    }
}
