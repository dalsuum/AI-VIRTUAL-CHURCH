<?php

namespace Tests\Feature;

use App\Domains\Church\Models\Church;
use App\Domains\Church\Models\ChurchMembership;
use App\Enums\ChurchRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deployment-oriented regression guard for the one production data step. Mirrors the
 * scenario: a populated database with some memberships already present must gain exactly
 * the missing memberships, create the default church once, and be safe to rerun.
 */
class BackfillDefaultChurchTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_is_idempotent_and_fills_only_missing_memberships(): void
    {
        // 12 users; 5 already belong to a pre-existing default church.
        $users = collect(range(1, 12))->map(fn () => $this->makeUser());
        $church = Church::create(['name' => 'Virtual Church', 'slug' => 'default']);
        foreach ($users->take(5) as $u) {
            ChurchMembership::create([
                'church_id' => $church->id, 'user_id' => $u->id, 'role' => ChurchRole::MEMBER,
                'status' => ChurchMembership::STATUS_ACTIVE, 'joined_at' => now(),
            ]);
        }

        $this->artisan('community:backfill-default-church')->assertSuccessful();

        // Default church not duplicated; only the 7 missing memberships created.
        $this->assertSame(1, Church::where('slug', 'default')->count());
        $this->assertSame(12, ChurchMembership::where('church_id', $church->id)->count());

        // Rerun inserts nothing.
        $this->artisan('community:backfill-default-church')->assertSuccessful();
        $this->assertSame(12, ChurchMembership::where('church_id', $church->id)->count());
    }

    public function test_backfill_creates_the_default_church_when_absent(): void
    {
        $this->makeUser();
        $this->makeUser();
        $this->assertSame(0, Church::count());

        $this->artisan('community:backfill-default-church')->assertSuccessful();

        $this->assertSame(1, Church::where('slug', 'default')->count());
        $this->assertSame(2, ChurchMembership::count());
    }
}
