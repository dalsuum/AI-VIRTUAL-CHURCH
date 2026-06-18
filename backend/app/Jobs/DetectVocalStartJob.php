<?php

namespace App\Jobs;

/**
 * Father's Day (Special Day) MV — vocal-onset detection (REMOVABLE).
 * Runs Demucs (isolated venv) on the admin song to find when the singing starts,
 * so the renderer holds the lyrics through the instrumental intro. Computed once
 * per uploaded song and cached in config.json; dispatched from
 * FathersDayController::adminUploadSong. Heavy/slow → its own queued job.
 */

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class DetectVocalStartJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;   // Demucs on CPU for a 90s clip can take minutes
    public int $tries   = 1;

    private const CONFIG = 'fathersday/config.json';

    public function __construct()
    {
        // Same dedicated queue as the renders — heavy Demucs work stays off the
        // worship 'default' worker.
        $this->onQueue('fathersday');
    }

    public function handle(): void
    {
        $song = $this->songPath();
        if (! $song) {
            $this->write(0.0, 'failed');
            return;
        }

        $python = base_path('../workers/.venv-demucs/bin/python');
        $script = base_path('../workers/tools/vocal_start.py');
        if (! is_file($python) || ! is_file($script)) {
            Log::warning('FathersDay vocal detection skipped: demucs venv/script missing.');
            $this->write(0.0, 'failed');
            return;
        }

        try {
            $p = new Process([$python, $script, $song]);
            $p->setTimeout($this->timeout);
            // Keep CPU/RAM pressure off the worship workers.
            $p->setEnv(['OMP_NUM_THREADS' => '2', 'PYTORCH_NUM_THREADS' => '2']);
            $p->run();

            $out = trim($p->getOutput());
            $json = json_decode($out !== '' ? substr($out, strrpos($out, '{') ?: 0) : '', true);
            if (! $p->isSuccessful() || ! is_array($json) || ! isset($json['vocal_start'])) {
                Log::warning('FathersDay vocal detection failed: ' . $p->getErrorOutput());
                $this->write(0.0, 'failed');
                return;
            }

            $this->write(max(0.0, (float) $json['vocal_start']), 'ready');
        } catch (\Throwable $e) {
            Log::warning('FathersDay vocal detection error: ' . $e->getMessage());
            $this->write(0.0, 'failed');
        }
    }

    private function songPath(): ?string
    {
        foreach (['mp3', 'wav'] as $ext) {
            $rel = "fathersday/song.{$ext}";
            if (Storage::exists($rel)) {
                return Storage::path($rel);
            }
        }
        return null;
    }

    /** Merge the detected start + status back into config.json. */
    private function write(float $start, string $status): void
    {
        $config = Storage::exists(self::CONFIG)
            ? (json_decode((string) Storage::get(self::CONFIG), true) ?: [])
            : [];
        $config['vocal_start']        = $start;
        $config['vocal_start_status'] = $status;   // detecting | ready | failed
        Storage::put(self::CONFIG, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        @chmod(Storage::path(self::CONFIG), 0664);
    }
}
