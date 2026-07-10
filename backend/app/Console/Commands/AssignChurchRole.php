<?php

namespace App\Console\Commands;

use App\Domains\Church\Models\Church;
use App\Domains\Church\Models\ChurchMembership;
use App\Enums\ChurchRole;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Bootstrap/break-glass assignment of a CHURCH role (church_memberships.role —
 * NOT the platform users.role). Exists because church-role promotion is itself
 * elder+-gated (ChurchPolicy::manage), so a fresh deployment has no one able to
 * appoint the first leadership: every backfilled membership starts as MEMBER.
 * Shell access on the box is the authorization boundary, as with any artisan
 * command. The durable fix — a church-run Members governance UI with explicit
 * escalation rules — is deliberately deferred to evidence-driven work (see
 * docs/OBSERVATION_LOG_V1.3.md, Administration).
 *
 *   php artisan church:assign-role pastor@example.com pastor
 *   php artisan church:assign-role leader@example.com leader --church-id=2
 */
class AssignChurchRole extends Command
{
    protected $signature = 'church:assign-role
        {email : The user\'s account email}
        {role : guest|member|leader|deacon|elder|pastor|owner}
        {--church-id= : Target church id (required only when several churches exist)}';

    protected $description = 'Assign a contextual church role to a user (bootstrap for church governance)';

    public function handle(): int
    {
        $role = ChurchRole::tryFrom(strtolower((string) $this->argument('role')));
        if (! $role) {
            $this->error('Unknown role. Valid: '.implode('|', array_column(ChurchRole::cases(), 'value')));

            return self::FAILURE;
        }

        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }
        if ($user->isGuestAccount()) {
            $this->error('Anonymous guest accounts cannot hold church roles.');

            return self::FAILURE;
        }

        $church = $this->option('church-id')
            ? Church::find($this->option('church-id'))
            : (Church::count() === 1 ? Church::first() : null);
        if (! $church) {
            $this->error('Church not found — pass --church-id (multiple churches exist, or the id is wrong).');

            return self::FAILURE;
        }

        $membership = ChurchMembership::firstOrCreate(
            ['church_id' => $church->id, 'user_id' => $user->id],
            ['role' => ChurchRole::MEMBER, 'status' => ChurchMembership::STATUS_ACTIVE, 'joined_at' => now()],
        );

        $before = $membership->role->value;
        $membership->forceFill(['role' => $role, 'status' => ChurchMembership::STATUS_ACTIVE])->save();

        $this->info("{$user->email} @ {$church->name}: {$before} → {$role->value}");
        logger()->info('church:assign-role', [
            'church_id' => $church->id, 'user_id' => $user->id,
            'from' => $before, 'to' => $role->value,
        ]);

        return self::SUCCESS;
    }
}
