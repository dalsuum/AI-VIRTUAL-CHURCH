<?php

namespace Tests\Unit;

use App\Enums\LedgerType;
use App\Exceptions\InsufficientTokensException;
use App\Models\TokenLedger;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenServiceTest extends TestCase
{
    use RefreshDatabase;

    private TokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = app(TokenService::class);
    }

    public function test_grant_credits_balance_and_writes_a_ledger_row(): void
    {
        $user = $this->makeUser(['token_balance' => 0]);

        $this->tokens->grant($user, 25, LedgerType::GRANT, 'welcome');

        $this->assertSame(25, (int) $user->fresh()->token_balance);
        $this->assertDatabaseHas('token_ledger', [
            'user_id'       => $user->id,
            'amount'        => 25,
            'balance_after' => 25,
            'type'          => 'grant',
        ]);
    }

    public function test_refill_monthly_sets_balance_to_allowance_and_stamps_time(): void
    {
        $user = $this->makeUser(['subscription_plan' => 'member', 'token_balance' => 13]);

        $this->tokens->refillMonthly($user);

        $user->refresh();
        $this->assertSame(100, (int) $user->token_balance);
        $this->assertSame(100, (int) $user->monthly_allowance);
        $this->assertNotNull($user->tokens_refilled_at);
        $this->assertDatabaseHas('token_ledger', ['user_id' => $user->id, 'type' => 'refill']);
    }

    public function test_refill_does_not_stack_allocations(): void
    {
        $user = $this->makeUser(['subscription_plan' => 'member', 'token_balance' => 0]);

        $this->tokens->refillMonthly($user);
        $this->tokens->refillMonthly($user);

        // Allowance is a reset, not an accumulation.
        $this->assertSame(100, (int) $user->fresh()->token_balance);
    }

    public function test_refill_is_a_noop_for_guest_accounts(): void
    {
        $guest = $this->makeUser(['email' => 'walkup@guest.local', 'token_balance' => 0]);

        $this->tokens->refillMonthly($guest);

        $this->assertSame(0, (int) $guest->fresh()->token_balance);
        $this->assertSame(0, TokenLedger::where('user_id', $guest->id)->count());
    }

    public function test_spend_debits_and_throws_when_short(): void
    {
        $user = $this->makeUser(['token_balance' => 2]);

        $this->tokens->spend($user, 'service', 'svc-1', 2);
        $this->assertSame(0, (int) $user->fresh()->token_balance);

        $this->expectException(InsufficientTokensException::class);
        $this->tokens->spend($user, 'service', 'svc-2', 1);
    }

    public function test_reserve_then_rollback_refunds_the_wallet(): void
    {
        $user = $this->makeUser(['token_balance' => 5]);

        $reservation = $this->tokens->reserve($user, 'service', 'ref-1', 3);
        $this->assertSame(2, (int) $user->fresh()->token_balance);

        $this->tokens->rollback($reservation);
        $this->assertSame(5, (int) $user->fresh()->token_balance);

        // Rollback is idempotent.
        $this->tokens->rollback($reservation);
        $this->assertSame(5, (int) $user->fresh()->token_balance);
    }

    public function test_reserve_then_commit_keeps_the_debit(): void
    {
        $user = $this->makeUser(['token_balance' => 5]);

        $reservation = $this->tokens->reserve($user, 'service', 'ref-2', 3);
        $this->tokens->commit($reservation);

        $this->assertSame(2, (int) $user->fresh()->token_balance);
    }
}
