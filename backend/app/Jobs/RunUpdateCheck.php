<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs the Python update_checker.py script (as the queue-worker user, simon) and
 * writes a fresh snapshot to /tmp/aivc_update_status.json. Optionally performs a
 * git pull before checking package versions.
 */
class RunUpdateCheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 1;

    public function __construct(public readonly bool $gitPull = false) {}

    public function handle(): void
    {
        $python  = '/opt/ai-church/workers/.venv/bin/python';
        $checker = '/opt/ai-church/workers/update_checker.py';
        $args    = $this->gitPull ? ' --pull' : '';

        // Runs synchronously in the queue-worker process (user: simon).
        exec("{$python} {$checker}{$args} 2>&1");
    }
}
