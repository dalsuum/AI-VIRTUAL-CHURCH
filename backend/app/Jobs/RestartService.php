<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Restarts a whitelisted systemd service via `sudo systemctl restart`.
 *
 * Prerequisite — add this sudoers rule (run `sudo visudo -f /etc/sudoers.d/aivc`):
 *   simon ALL=(root) NOPASSWD: /bin/systemctl restart aivc-workers.service, \
 *     /bin/systemctl restart aivc-workers-music.service, \
 *     /bin/systemctl restart aivc-bridge.service, \
 *     /bin/systemctl restart aivc-queue.service, \
 *     /bin/systemctl restart aivc-scheduler.service, \
 *     /bin/systemctl restart aivc-tedim-api.service, \
 *     /bin/systemctl restart aivc-burmese-api.service, \
 *     /bin/systemctl restart aivc-mms-tts.service
 */
class RestartService implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 1;

    private const ALLOWED = [
        'aivc-workers',
        'aivc-workers-music',
        'aivc-bridge',
        'aivc-queue',
        'aivc-scheduler',
        'aivc-tedim-api',
        'aivc-burmese-api',
        'aivc-mms-tts',
    ];

    public function __construct(public readonly string $service) {}

    public function handle(): void
    {
        if (!in_array($this->service, self::ALLOWED, true)) {
            return;
        }

        $svc = escapeshellarg($this->service . '.service');
        exec("sudo systemctl restart {$svc} 2>&1", $output, $code);

        // Give the unit a moment to settle before refreshing service statuses.
        sleep(3);
        RunUpdateCheck::dispatchSync();
    }
}
