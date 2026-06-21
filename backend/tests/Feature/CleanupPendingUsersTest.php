<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanupPendingUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_pending_users_past_the_window(): void
    {
        $stale = $this->makeUser([
            'status'            => User::STATUS_PENDING,
            'email_verified_at' => null,
        ]);
        // 24h window (config); created 48h ago.
        User::whereKey($stale->id)->update(['created_at' => now()->subHours(48)]);

        $this->artisan('users:cleanup-pending')->assertSuccessful();

        $this->assertNull(User::find($stale->id), 'Expired pending user deleted');
    }

    public function test_preserves_recent_pending_users(): void
    {
        $recent = $this->makeUser([
            'status'            => User::STATUS_PENDING,
            'email_verified_at' => null,
        ]);
        User::whereKey($recent->id)->update(['created_at' => now()->subHours(1)]);

        $this->artisan('users:cleanup-pending')->assertSuccessful();

        $this->assertNotNull(User::find($recent->id), 'Still-within-window pending user kept');
    }

    public function test_never_deletes_active_users_even_if_old(): void
    {
        $active = $this->makeUser(['status' => User::STATUS_ACTIVE]);
        User::whereKey($active->id)->update(['created_at' => now()->subYears(1)]);

        $this->artisan('users:cleanup-pending')->assertSuccessful();

        $this->assertNotNull(User::find($active->id), 'Active users are never pruned');
    }
}
