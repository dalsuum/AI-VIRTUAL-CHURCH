<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AccountActivationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_pending_account_without_logging_in(): void
    {
        Notification::fake();

        $res = $this->postJson('/api/register', [
            'name'     => 'Grace Hopper',
            'email'    => 'grace@example.com',
            'password' => 'Sup3r-Secret-Pw!',
        ]);

        $res->assertStatus(201)
            ->assertJson(['message' => 'Please check your email to activate your account.'])
            // Must NOT leak a user object or auto-login.
            ->assertJsonMissingPath('user');

        $user = User::where('email', 'grace@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame(User::STATUS_PENDING, $user->status);
        $this->assertNull($user->email_verified_at);
        $this->assertSame(0, (int) $user->token_balance, 'No tokens granted before activation');
        $this->assertNotNull($user->activation_token, 'Hashed activation token stored');

        // No session was established (no auth cookie / guest).
        $this->assertGuest();
    }

    public function test_register_sends_activation_email(): void
    {
        Notification::fake();

        $this->postJson('/api/register', [
            'name'     => 'Ada',
            'email'    => 'ada@example.com',
            'password' => 'Sup3r-Secret-Pw!',
        ])->assertStatus(201);

        Notification::assertSentTimes(AccountActivationNotification::class, 1);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        $this->makeUser(['email' => 'taken@example.com']);

        $this->postJson('/api/register', [
            'name'     => 'Dup',
            'email'    => 'taken@example.com',
            'password' => 'Sup3r-Secret-Pw!',
        ])->assertStatus(422);
    }

    public function test_register_requires_valid_fields(): void
    {
        $this->postJson('/api/register', ['email' => 'not-an-email'])
            ->assertStatus(422);
    }
}
