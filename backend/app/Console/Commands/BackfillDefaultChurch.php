<?php

namespace App\Console\Commands;

use App\Domains\Church\Models\Church;
use App\Domains\Church\Models\ChurchMembership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One production data step for the community platform: ensure a default church exists
 * and that every user has a membership in it. Single-church today; enforcement of
 * multi-tenancy is deferred to Phase 6, but every user needs a home church now so the
 * church-visibility tier and ChurchPolicy have something to resolve against.
 *
 * Safe by construction:
 *   - idempotent : the church is created only if absent; memberships only for users
 *                  lacking one — rerunning inserts zero rows.
 *   - transactional : the church lookup/creation is atomic (firstOrCreate).
 *   - resumable  : users are streamed with chunkById, and each missing membership is
 *                  created independently, so an interrupted run simply continues.
 *
 *   php artisan community:backfill-default-church
 */
class BackfillDefaultChurch extends Command
{
    protected $signature = 'community:backfill-default-church
        {--slug=default : Slug of the default church}
        {--name=Virtual Church : Display name when creating the church}
        {--chunk=500 : Users processed per batch}';

    protected $description = 'Ensure a default church exists and every user is a member of it';

    public function handle(): int
    {
        $church = DB::transaction(fn () => Church::firstOrCreate(
            ['slug' => $this->option('slug')],
            ['name' => $this->option('name')],
        ));

        $existing = ChurchMembership::where('church_id', $church->id)
            ->pluck('user_id')->flip();

        $created = 0;
        User::orderBy('id')->chunkById((int) $this->option('chunk'), function ($users) use ($church, $existing, &$created) {
            foreach ($users as $user) {
                if ($existing->has($user->id)) {
                    continue; // already a member — skip (idempotent)
                }
                ChurchMembership::create([
                    'church_id' => $church->id,
                    'user_id'   => $user->id,
                    'role'      => \App\Enums\ChurchRole::MEMBER,
                    'status'    => ChurchMembership::STATUS_ACTIVE,
                    'joined_at' => now(),
                ]);
                $created++;
            }
        });

        $this->info("Default church '{$church->slug}' ready; created {$created} membership(s).");

        return self::SUCCESS;
    }
}
