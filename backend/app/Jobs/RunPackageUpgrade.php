<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Upgrades a single Python package in the workers virtualenv, then re-runs the
 * update checker so the dashboard cache reflects the newly installed version.
 */
class RunPackageUpgrade implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // pip installs (especially torch) can take a while
    public int $tries   = 1;

    private const ALLOWED = [
        'edge-tts', 'anthropic', 'celery', 'redis', 'requests',
        'torch', 'transformers', 'httpx', 'fastapi', 'uvicorn', 'boto3', 'scipy',
    ];

    public function __construct(public readonly string $package) {}

    public function handle(): void
    {
        if (!in_array($this->package, self::ALLOWED, true)) {
            return;
        }

        $pip = '/opt/ai-church/workers/.venv/bin/pip';
        // escapeshellarg is redundant here (package is allowlisted) but kept for clarity.
        exec($pip . ' install --upgrade ' . escapeshellarg($this->package) . ' 2>&1');

        // Refresh the cache so the dashboard shows the new version immediately.
        RunUpdateCheck::dispatchSync();
    }
}
