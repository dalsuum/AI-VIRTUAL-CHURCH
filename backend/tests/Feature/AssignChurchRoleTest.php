<?php

namespace Tests\Feature;

use App\Domains\Church\Models\Church;
use App\Domains\Church\Models\ChurchMembership;
use App\Enums\ChurchRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignChurchRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstraps_leadership_and_is_idempotent(): void
    {
        $church = Church::create(['name' => 'Grace', 'slug' => 'grace']);
        $user   = $this->makeUser();

        // No membership yet: the command creates one with the requested role.
        $this->artisan('church:assign-role', ['email' => $user->email, 'role' => 'pastor'])
            ->assertExitCode(0);
        $this->assertDatabaseHas('church_memberships', [
            'church_id' => $church->id, 'user_id' => $user->id, 'role' => 'pastor', 'status' => 'active',
        ]);

        // The promoted user can now exercise church governance (the whole point).
        $this->assertTrue($user->fresh()->can('manage', $church));

        // Re-running (or demoting) updates the same canonical row.
        $this->artisan('church:assign-role', ['email' => $user->email, 'role' => 'member'])
            ->assertExitCode(0);
        $this->assertSame(1, ChurchMembership::where('user_id', $user->id)->count());
        $this->assertSame(ChurchRole::MEMBER, ChurchMembership::where('user_id', $user->id)->first()->role);
    }

    public function test_refuses_bad_input(): void
    {
        Church::create(['name' => 'Grace', 'slug' => 'grace']);
        $user  = $this->makeUser();
        $guest = $this->makeUser(['email' => 'walkup-'.uniqid().'@guest.local']);

        $this->artisan('church:assign-role', ['email' => $user->email, 'role' => 'bishop'])
            ->assertExitCode(1);   // not a ChurchRole
        $this->artisan('church:assign-role', ['email' => 'nobody@nowhere.test', 'role' => 'leader'])
            ->assertExitCode(1);   // unknown user
        $this->artisan('church:assign-role', ['email' => $guest->email, 'role' => 'leader'])
            ->assertExitCode(1);   // anonymous guests cannot hold church roles

        // Ambiguous church requires an explicit id.
        Church::create(['name' => 'Second', 'slug' => 'second']);
        $this->artisan('church:assign-role', ['email' => $user->email, 'role' => 'leader'])
            ->assertExitCode(1);
        $this->artisan('church:assign-role', [
            'email' => $user->email, 'role' => 'leader', '--church-id' => Church::where('slug', 'second')->value('id'),
        ])->assertExitCode(0);
    }
}
