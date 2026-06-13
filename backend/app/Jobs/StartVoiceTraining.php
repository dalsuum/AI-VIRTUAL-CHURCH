<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class StartVoiceTraining implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 1;

    public function __construct(
        public readonly ?int $userId = null,
        public readonly ?string $lang = null,
    ) {}

    public function handle(): void
    {
        $options = ['--ignore-window'];
        if ($this->userId !== null) {
            $options[] = '--user_id=' . $this->userId;
        }
        if ($this->lang !== null) {
            $options[] = '--lang=' . $this->lang;
        }

        $shell = implode(' ', [
            'cd',
            escapeshellarg(base_path()),
            '&&',
            'nohup',
            escapeshellarg(PHP_BINARY ?: 'php'),
            escapeshellarg(base_path('artisan')),
            'voice-studio:train-due',
            implode(' ', array_map('escapeshellarg', $options)),
            '>/dev/null',
            '2>&1',
            '&',
        ]);

        exec($shell, $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException('Could not start Voice Studio trainer.');
        }
    }
}
