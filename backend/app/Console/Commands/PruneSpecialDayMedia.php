<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Disk-retention sweep for the two removable, public-upload media features —
 * Special Day MV (fathersday) and Live Sticker (stickers).
 *
 * Both write per-job trees under storage/app/<feature>/jobs/<uuid>/ from public,
 * unauthenticated endpoints. The render jobs delete their own src/work scratch,
 * but the *finished* output (an MV mp4 / sticker png) was otherwise kept forever
 * — unbounded growth that eventually fills the filesystem. This command bounds
 * it: finished jobs are kept for a feature-specific window so shared links stay
 * alive, while abandoned uploads (uploaded but never rendered) are dropped fast
 * for privacy. Scheduled daily in routes/console.php and also called
 * opportunistically on the MV render path.
 *
 * Remove this command when removing both features.
 */
class PruneSpecialDayMedia extends Command
{
    protected $signature = 'media:prune';
    protected $description = 'Prune finished/abandoned Special Day MV + Live Sticker job dirs by age';

    // Finished outputs are kept this long so shared /v/<id> and /s/<id> links
    // keep working; abandoned uploads (no output produced) go after 1h.
    private const MV_KEEP_SECS      = 2592000;  // finished MVs: 30 days
    private const STICKER_KEEP_SECS = 31536000; // finished stickers: 365 days
    private const ABANDON_SECS      = 3600;     // uploaded but never rendered: 1h

    public function handle(): int
    {
        [$mv, $st] = self::sweep();
        $this->info("Pruned {$mv} Special Day MV job(s) and {$st} Live Sticker job(s).");

        return self::SUCCESS;
    }

    /**
     * Run both sweeps. Returns [mvPruned, stickerPruned]. Static so the render
     * path can call it opportunistically without booting an artisan command.
     */
    public static function sweep(): array
    {
        $mv = self::sweepTree('fathersday/jobs', 'output.mp4', self::MV_KEEP_SECS);
        $st = self::sweepTree('stickers/jobs', 'sticker_*.png', self::STICKER_KEEP_SECS);

        return [$mv, $st];
    }

    /**
     * Prune one feature's jobs dir. A job is "finished" when $finishedGlob
     * matches inside it (kept $keepSecs); otherwise it's abandoned (kept
     * ABANDON_SECS). Age is taken from the dir mtime.
     */
    private static function sweepTree(string $relJobsDir, string $finishedGlob, int $keepSecs): int
    {
        $base = Storage::path($relJobsDir);
        if (! is_dir($base)) {
            return 0;
        }

        $pruned = 0;
        foreach (glob("{$base}/*") ?: [] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $age      = time() - filemtime($dir);
            $finished = glob("{$dir}/{$finishedGlob}") !== [];
            $ttl      = $finished ? $keepSecs : self::ABANDON_SECS;
            if ($age > $ttl) {
                self::rrmdir($dir);
                $pruned++;
            }
        }

        return $pruned;
    }

    private static function rrmdir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $full = "{$path}/{$f}";
            is_dir($full) ? self::rrmdir($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
