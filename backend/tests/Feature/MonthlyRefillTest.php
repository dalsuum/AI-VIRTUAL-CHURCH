<?php

namespace Tests\Feature;

use App\Models\TokenLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyRefillTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_refills_active_members_once_per_month(): void
    {
        $user = $this->makeUser([
            'subscription_plan'  => 'member',
            'token_balance'      => 0,
            'tokens_refilled_at' => now()->subMonths(2),
        ]);

        $this->artisan('tokens:refill-monthly')->assertSuccessful();
        $this->assertSame(100, (int) $user->fresh()->token_balance);

        // Running again the same month must not double-grant (idempotent).
        $this->artisan('tokens:refill-monthly')->assertSuccessful();
        $this->assertSame(100, (int) $user->fresh()->token_balance);
        $this->assertSame(1, TokenLedger::where('user_id', $user->id)->where('type', 'refill')->count());
    }

    public function test_command_skips_pending_users(): void
    {
        $pending = $this->makeUser([
            'subscription_plan'  => 'member',
            'status'             => User::STATUS_PENDING,
            'email_verified_at'  => null,
            'token_balance'      => 0,
            'tokens_refilled_at' => null,
        ]);

        $this->artisan('tokens:refill-monthly')->assertSuccessful();

        // Never-activated accounts are granted on activation, not by the refill job.
        $this->assertSame(0, (int) $pending->fresh()->token_balance);
    }

    public function test_command_skips_guests(): void
    {
        $guest = $this->makeUser(['email' => 'walkup@guest.local', 'token_balance' => 0]);

        $this->artisan('tokens:refill-monthly')->assertSuccessful();

        $this->assertSame(0, (int) $guest->fresh()->token_balance);
    }
}
