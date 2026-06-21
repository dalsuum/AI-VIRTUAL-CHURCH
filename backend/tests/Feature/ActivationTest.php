<?php

namespace Tests\Feature;

use App\Models\TokenLedger;
use App\Models\User;
use App\Services\AccountActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivationTest extends TestCase
{
    use RefreshDatabase;

    private function pendingUser(): User
    {
        return $this->makeUser([
            'status'            => User::STATUS_PENDING,
            'email_verified_at' => null,
            'token_balance'     => 0,
        ]);
    }

    public function test_valid_activation_activates_and_grants_member_package(): void
    {
        $user  = $this->pendingUser();
        $token = app(AccountActivationService::class)->issueToken($user);

        $res = $this->get('/activate?token=' . $token);

        $res->assertOk()->assertSee('Account Activated');

        $user->refresh();
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->activation_token, 'Token is single-use (cleared)');
        $this->assertSame(100, (int) $user->token_balance, 'Member monthly allowance granted');

        // A ledger row backs the grant — no silent balance mutation.
        $this->assertDatabaseHas('token_ledger', [
            'user_id' => $user->id,
            'type'    => 'refill',
        ]);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $result = app(AccountActivationService::class)->activate(str_repeat('z', 64));
        $this->assertSame(AccountActivationService::RESULT_INVALID, $result['result']);

        $this->get('/activate?token=' . str_repeat('z', 64))
            ->assertOk()->assertSee('Invalid activation link');
    }

    public function test_malformed_token_length_is_rejected_without_db_lookup(): void
    {
        $this->get('/activate?token=short')->assertOk()->assertSee('Invalid activation link');
    }

    public function test_expired_token_is_rejected(): void
    {
        $user  = $this->pendingUser();
        $token = app(AccountActivationService::class)->issueToken($user);
        $user->forceFill(['activation_expires_at' => now()->subHour()])->save();

        $this->get('/activate?token=' . $token)
            ->assertOk()->assertSee('Activation link expired');

        $user->refresh();
        $this->assertSame(User::STATUS_PENDING, $user->status, 'Expired link must not activate');
        $this->assertSame(0, (int) $user->token_balance);
    }

    public function test_activation_is_idempotent_on_replay(): void
    {
        $user  = $this->pendingUser();
        $svc   = app(AccountActivationService::class);
        $token = $svc->issueToken($user);

        $first = $svc->activate($token);
        $this->assertSame(AccountActivationService::RESULT_ACTIVATED, $first['result']);
        $balanceAfterFirst = (int) $user->fresh()->token_balance;

        // Re-clicking the same (now-consumed) link must not re-grant tokens.
        $second = $svc->activate($token);
        $this->assertSame(AccountActivationService::RESULT_INVALID, $second['result']);
        $this->assertSame($balanceAfterFirst, (int) $user->fresh()->token_balance);

        // Exactly one refill ledger row exists despite the replay.
        $this->assertSame(1, TokenLedger::where('user_id', $user->id)->where('type', 'refill')->count());
    }

    public function test_already_active_account_reports_success_without_regrant(): void
    {
        $user = $this->makeUser(['status' => User::STATUS_ACTIVE, 'token_balance' => 100]);
        // Simulate an account that still carries a token (re-issued) but is already verified.
        $token = app(AccountActivationService::class)->issueToken($user);

        $result = app(AccountActivationService::class)->activate($token);

        $this->assertSame(AccountActivationService::RESULT_ALREADY, $result['result']);
        $this->assertSame(100, (int) $user->fresh()->token_balance);
        $this->assertNull($user->fresh()->activation_token);
    }
}
