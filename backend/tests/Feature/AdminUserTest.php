<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AccountActivationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return $this->makeUser(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
    }

    public function test_admin_created_user_is_active_and_granted_by_default(): void
    {
        config(['account.admin_requires_verification' => false]);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/users', [
                'name' => 'Pat', 'email' => 'pat@example.com', 'role' => 'member',
            ])
            ->assertStatus(201);

        $user = User::where('email', 'pat@example.com')->first();
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(100, (int) $user->token_balance, 'Member package granted immediately');
        $this->assertDatabaseHas('token_ledger', ['user_id' => $user->id, 'type' => 'refill']);
    }

    public function test_admin_created_user_requires_verification_when_configured(): void
    {
        Notification::fake();
        config(['account.admin_requires_verification' => true]);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/users', [
                'name' => 'Sam', 'email' => 'sam@example.com', 'role' => 'member',
            ])
            ->assertStatus(201);

        $user = User::where('email', 'sam@example.com')->first();
        $this->assertSame(User::STATUS_PENDING, $user->status);
        $this->assertSame(0, (int) $user->token_balance, 'No grant until activation');
        Notification::assertSentTimes(AccountActivationNotification::class, 1);
    }

    public function test_admin_grant_tokens_credits_wallet_with_ledger(): void
    {
        $target = $this->makeUser(['token_balance' => 10]);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/users/{$target->id}/tokens", ['amount' => 40])
            ->assertOk()
            ->assertJson(['ok' => true, 'token_balance' => 50]);

        $this->assertDatabaseHas('token_ledger', [
            'user_id' => $target->id,
            'amount'  => 40,
            'type'    => 'adjustment',
        ]);
    }

    public function test_admin_grant_tokens_rejects_guest_wallet(): void
    {
        $guest = $this->makeUser(['email' => 'walkup@guest.local']);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/users/{$guest->id}/tokens", ['amount' => 40])
            ->assertStatus(422);
    }
}
