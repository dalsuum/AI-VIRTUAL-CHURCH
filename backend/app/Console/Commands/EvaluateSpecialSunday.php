<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\SpecialSundayResolver;
use Illuminate\Console\Command;

/**
 * Daily evaluation of the special-Sunday window. The resolver is also consulted
 * live at service-dispatch time and on the public endpoint, so this command is
 * not on the critical path — it warms the per-language cache and logs the active
 * observance (or absence) once a day for operability. Scheduled in
 * routes/console.php.
 */
class EvaluateSpecialSunday extends Command
{
    protected $signature = 'special-sunday:evaluate';
    protected $description = 'Evaluate the special-Sunday window and warm the current-observance cache';

    public function handle(SpecialSundayResolver $resolver): int
    {
        $active = $resolver->activeFor(now());

        if ($active === null) {
            $this->info('No special Sunday active in the current window.');

            return self::SUCCESS;
        }

        // Warm the cache the public endpoint / dispatch path reads.
        foreach (Setting::LANGUAGES as $lang) {
            $resolver->currentPayload($lang);
        }

        $this->info(sprintf(
            'Active special Sunday: %s (Sunday %s, priority %d).',
            $active['special']->key,
            $active['sunday']->toDateString(),
            $active['special']->priority,
        ));

        return self::SUCCESS;
    }
}
