<?php

namespace App\Jobs;

/**
 * Live Sticker composite — SELF-CONTAINED & REMOVABLE.
 * Runs workers/tools/sticker_render.py on the uploaded photo to produce 5 PNG
 * stickers. See App\Http\Controllers\StickerController for the surrounding
 * feature and removal steps. No worship-pipeline code is touched.
 */

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class RenderStickerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 150;   // 1 AI repaint (OpenRouter) + cutout; fail fast
    public int $tries   = 1;

    public function __construct(public string $jobId)
    {
        // Reuse the dedicated render queue so this never blocks the worship
        // 'default' worker. Served by the same aivc-fathersday-render workers.
        $this->onQueue('fathersday');
    }

    public function handle(): void
    {
        $dir = Storage::path("stickers/jobs/{$this->jobId}");

        try {
            $python = base_path('../workers/.venv/bin/python');
            $script = base_path('../workers/tools/sticker_render.py');
            if (! is_file($python) || ! is_file($script)) {
                throw new \RuntimeException('sticker engine missing');
            }

            $p = new Process([$python, $script, 'render', $dir]);
            $p->setTimeout($this->timeout);
            // Keep CPU pressure off the worship workers.
            $p->setEnv(['OMP_NUM_THREADS' => '2']);
            $p->run();
            if (! $p->isSuccessful()) {
                throw new \RuntimeException('sticker render failed: ' . trim($p->getErrorOutput()));
            }

            // Open the finished PNGs + status to the web server (different user).
            foreach (glob("{$dir}/*.png") ?: [] as $png) {
                @chmod($png, 0644);
            }
            @chmod("{$dir}/status.json", 0664);
        } catch (\Throwable $e) {
            Log::error("Sticker render {$this->jobId} failed: {$e->getMessage()}");
            $this->writeError($dir);
        } finally {
            // Drop the original upload; keep only the sticker PNGs + status.
            $this->rrmdir("{$dir}/src");
            @unlink("{$dir}/input.json");
        }
    }

    private function writeError(string $dir): void
    {
        @file_put_contents("{$dir}/status.json", json_encode([
            'status'  => 'error',
            'message' => 'Sorry — the stickers could not be generated. Please try again.',
        ]));
        @chmod("{$dir}/status.json", 0664);
    }

    private function rrmdir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $full = "{$path}/{$f}";
            is_dir($full) ? $this->rrmdir($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
