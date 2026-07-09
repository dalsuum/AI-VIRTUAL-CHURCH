<?php

namespace App\Http\Controllers;

/**
 * ============================================================================
 *  Live Sticker maker — SELF-CONTAINED & REMOVABLE
 * ============================================================================
 * A standalone fun tool: a visitor uploads any photo (vertical/horizontal),
 * we auto-detect the face and suggest a square crop they can adjust, then we
 * composite FIVE random PNG stickers (random frame / font / colour / short
 * text + emoji). The sticker text comes either from the admin Father's Day
 * song lyrics (special-day mode) or from free text the visitor types (which we
 * lightly auto-correct for English).
 *
 * Nothing here touches the worship pipeline. To remove the whole feature:
 *   1. delete this controller + App\Jobs\RenderStickerJob
 *   2. delete workers/tools/sticker_render.py
 *   3. delete the "Live Sticker" route block in routes/api.php
 *   4. delete storage/app/stickers/
 *   5. delete frontend LiveSticker.vue + its wiring
 *
 * No DB migration: uploads + outputs live as plain files under
 * storage/app/stickers/jobs/<id>/. The face-detect step runs synchronously
 * (fast); the 5-sticker composite runs on the dedicated 'fathersday' queue.
 */

use App\Jobs\RenderStickerJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\Process;

class StickerController extends Controller
{
    private const DIR         = 'stickers';
    private const CONFIG      = 'stickers/config.json';
    private const COUNT       = 1;     // stickers produced per job (1 AI repaint = low cost)
    private const MAX_CHARS   = 120;   // sticker source text length cap
    // Retention: finished stickers are kept long-term so shared LINKS keep
    // working (~1 year); only abandoned uploads (detect without render) are
    // cleaned up quickly for privacy.
    private const KEEP_SECS    = 31536000; // finished stickers: 365 days
    private const ABANDON_SECS = 3600;     // photo uploaded but never rendered: 1h
    // Global daily budget on AI repaints (each render = a paid OpenRouter call).
    // Caps cost even under distributed abuse that slips past per-IP throttling.
    private const DAILY_CAP    = 500;
    // Hard storage ceiling for the feature tree — a cleanup bug must not be able
    // to fill the host; past this, new renders are refused.
    private const MAX_FEATURE_BYTES = 2147483648; // 2 GB

    /** Admin-managed feature config (enable flag + fallback page copy). */
    private function config(): array
    {
        $defaults = [
            'enabled'  => false,
            'title'    => 'Make a Live Sticker',
            'subtitle' => 'Upload a photo — we\'ll turn it into a fun art sticker.',
        ];
        $data = Storage::exists(self::CONFIG)
            ? json_decode((string) Storage::get(self::CONFIG), true)
            : [];

        return is_array($data) ? array_merge($defaults, $data) : $defaults;
    }

    /**
     * Public config: feature flag + page copy + caption suggestions. The theme
     * (title/occasion/suggestions) follows the CURRENT Special Sunday when one is
     * active, falling back to the admin-set copy otherwise.
     */
    public function publicConfig(\App\Services\SpecialSundayResolver $resolver): JsonResponse
    {
        $c = $this->config();
        $observance = $c['enabled'] ? $resolver->currentPayload('en') : null;

        // The page title is admin-controlled (e.g. "Happy Father's Day") and is
        // always burned onto the sticker as the static bottom title. The current
        // observance only drives the art theme + caption suggestions.
        $title    = $c['title'];
        $occasion = $observance['title'] ?? '';
        $suggestions = $this->suggestions($resolver, $observance);

        return response()->json([
            'enabled'     => (bool) $c['enabled'],
            'title'       => $title,
            'subtitle'    => $c['subtitle'],
            'occasion'    => $occasion,
            'count'       => self::COUNT,
            'max_chars'   => self::MAX_CHARS,
            'suggestions' => $suggestions,
            // Lyric lines grouped per song so the visitor picks a song then a line.
            'songs'       => $this->songLyrics(),
        ]);
    }

    // ---- Admin ------------------------------------------------------------

    public function adminShow(): JsonResponse
    {
        $c = $this->config();

        return response()->json([
            'enabled'  => (bool) $c['enabled'],
            'title'    => $c['title'],
            'subtitle' => $c['subtitle'],
            'usage'    => $this->usageSummary($c),
        ]);
    }

    /** Reset the visitor render counters (cumulative + today) back to zero. */
    public function resetUsage(): JsonResponse
    {
        $c = $this->config();
        $c['usage'] = ['total' => 0, 'today' => 0, 'date' => now()->toDateString()];
        Storage::put(self::CONFIG, json_encode($c));
        @chmod(Storage::path(self::CONFIG), 0664);

        return $this->adminShow();
    }

    /** Normalise the stored usage counter for display (today resets daily). */
    private function usageSummary(array $c): array
    {
        $u = is_array($c['usage'] ?? null) ? $c['usage'] : [];
        $today = (($u['date'] ?? null) === now()->toDateString()) ? (int) ($u['today'] ?? 0) : 0;

        return ['total' => (int) ($u['total'] ?? 0), 'today' => $today];
    }

    public function adminSave(Request $request): JsonResponse
    {
        $v = $request->validate([
            'enabled'  => ['required', 'boolean'],
            'title'    => ['nullable', 'string', 'max:120'],
            'subtitle' => ['nullable', 'string', 'max:200'],
        ]);

        $c = $this->config();
        $c['enabled']  = (bool) $v['enabled'];
        $c['title']    = $v['title'] ?: 'Make a Live Sticker';
        $c['subtitle'] = $v['subtitle'] ?: 'Upload a photo — we\'ll turn it into a fun art sticker.';
        $c['updated_at'] = now()->toIso8601String();

        Storage::put(self::CONFIG, json_encode($c));
        @chmod(Storage::path(self::CONFIG), 0664);

        return response()->json($c + ['ok' => true]);
    }

    /** Caption suggestions: the current observance's titles, else lyric lines. */
    private function suggestions(\App\Services\SpecialSundayResolver $resolver, ?array $observance): array
    {
        $out = [];
        if ($observance) {
            foreach (['en', 'my', 'td'] as $lang) {
                $p = $resolver->currentPayload($lang);
                if (! empty($p['title'])) {
                    $out[$p['title']] = true;
                }
            }
        }
        foreach ($this->lyricSuggestions() as $line) {
            $out[$line] = true;
        }

        return array_slice(array_keys($out), 0, 40);
    }

    /**
     * Step 1 — accept a single photo, auto-detect the face and return a square
     * crop box the frontend renders for manual adjustment. The photo is kept
     * server-side under a token so step 2 needn't re-upload it.
     */
    public function detect(Request $request): JsonResponse
    {
        if (! $this->config()['enabled']) {
            return response()->json(['message' => 'This feature is not available right now.'], 404);
        }

        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
        ]);

        $this->pruneStale();

        $token  = (string) Str::uuid();
        $jobDir = self::DIR . "/jobs/{$token}";
        $ext    = strtolower($request->file('photo')->getClientOriginalExtension() ?: 'jpg');
        $ext    = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? $ext : 'jpg';
        // Never trust the client filename; store under a fixed name.
        $request->file('photo')->storeAs("{$jobDir}/src", "photo.{$ext}");
        $this->ensureBasePerms();
        $this->openPerms(Storage::path($jobDir));

        $photo = Storage::path("{$jobDir}/src/photo.{$ext}");

        // Defence in depth: verify the bytes really are a JPEG/PNG/WebP (not just
        // a trusted extension/MIME) and reject decompression bombs by declared
        // pixel count before any decoder (cv2/Pillow) touches the file.
        $info = @getimagesize($photo);
        $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
        if ($info === false || ! in_array($info[2] ?? 0, $allowed, true)) {
            $this->rrmdir(Storage::path($jobDir));
            return response()->json(['message' => 'That file is not a valid image.'], 422);
        }
        if (((int) $info[0]) * ((int) $info[1]) > 60_000_000) {
            $this->rrmdir(Storage::path($jobDir));
            return response()->json(['message' => 'That photo is too large — please resize it and try again.'], 422);
        }
        $result = $this->runPython(['detect', $photo], 60);
        $data   = json_decode($result, true);
        if (! is_array($data) || ! isset($data['w'], $data['h'])) {
            return response()->json(['message' => 'Could not read that image.'], 422);
        }

        return response()->json([
            'token' => $token,
            'w'     => (int) $data['w'],
            'h'     => (int) $data['h'],
            'box'   => $data['box'] ?? null,
        ]);
    }

    /**
     * Step 2 — using the token from detect(), queue the 5-sticker composite
     * with the (possibly user-adjusted) crop box and the chosen text.
     */
    public function render(Request $request, \App\Services\SpecialSundayResolver $resolver): JsonResponse
    {
        if (! $this->config()['enabled']) {
            return response()->json(['message' => 'This feature is not available right now.'], 404);
        }

        $v = $request->validate([
            'token'  => ['required', 'string'],
            'text'   => ['nullable', 'string', 'max:' . self::MAX_CHARS],
            'source' => ['nullable', 'in:lyrics,manual'],
            'crop'   => ['nullable', 'array'],
            'crop.x' => ['nullable', 'numeric', 'min:0'],
            'crop.y' => ['nullable', 'numeric', 'min:0'],
            'crop.w' => ['nullable', 'numeric', 'min:1'],
            'crop.h' => ['nullable', 'numeric', 'min:1'],
        ]);

        // Theme the AI repaint after the current Special Sunday, if any. The
        // admin page title is always burned onto the sticker as a static title.
        $occasion = $resolver->currentPayload('en')['title'] ?? '';
        $title    = $this->config()['title'];

        $token  = $this->safeId($v['token']);
        $jobDir = self::DIR . "/jobs/{$token}";
        if (! $token || ! Storage::exists("{$jobDir}/src")) {
            return response()->json(['message' => 'Upload expired — please add the photo again.'], 422);
        }

        // Idempotency: a render was already queued for this upload (double-click,
        // refresh, proxy retry) — return the existing job rather than dispatching
        // a second paid repaint. Keyed on the upload token (one render per upload).
        if (Storage::exists("{$jobDir}/status.json")) {
            return response()->json(['job_id' => $token, 'status' => 'queued', 'deduped' => true]);
        }

        // Global daily budget: cap paid OpenRouter repaints even under abuse that
        // slips past per-IP throttling.
        if ($this->usageSummary($this->config())['today'] >= self::DAILY_CAP) {
            return response()->json([
                'message' => 'We\'ve hit today\'s sticker limit — please try again tomorrow.',
            ], 429);
        }

        // Storage safety valve (a cleanup bug must not be able to fill the host).
        if ($this->featureBytes() > self::MAX_FEATURE_BYTES) {
            \Log::warning('Sticker storage ceiling hit; refusing new render.');
            return response()->json([
                'message' => 'We\'re a bit busy right now — please try again later.',
            ], 503);
        }

        $photos = glob(Storage::path("{$jobDir}/src/*"));
        if (! $photos) {
            return response()->json(['message' => 'Upload expired — please add the photo again.'], 422);
        }
        $photoRel = 'src/' . basename($photos[0]);

        $crop = null;
        if (! empty($v['crop']) && isset($v['crop']['x'], $v['crop']['y'], $v['crop']['w'], $v['crop']['h'])) {
            $crop = [
                'x' => (int) $v['crop']['x'], 'y' => (int) $v['crop']['y'],
                'w' => (int) $v['crop']['w'], 'h' => (int) $v['crop']['h'],
            ];
        }

        Storage::put("{$jobDir}/input.json", json_encode([
            'photo'       => $photoRel,
            'crop'        => $crop,
            'text'        => trim((string) ($v['text'] ?? '')),
            'source'      => $v['source'] ?? 'manual',
            'theme'       => $occasion,
            'title'       => $title,
            'autocorrect' => true,
        ]));
        Storage::put("{$jobDir}/status.json", json_encode([
            'status'     => 'queued',
            'progress'   => 0,
            'stage'      => 'Queued',
            'created_at' => now()->toIso8601String(),
        ]));
        $this->openPerms(Storage::path($jobDir));

        RenderStickerJob::dispatch($token);
        $this->recordUse();

        return response()->json(['job_id' => $token, 'status' => 'queued']);
    }

    public function status(string $jobId): JsonResponse
    {
        $jobId = $this->safeId($jobId);
        $path  = self::DIR . "/jobs/{$jobId}/status.json";
        if (! $jobId || ! Storage::exists($path)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $data = json_decode((string) Storage::get($path), true) ?: ['status' => 'unknown'];
        if (($data['status'] ?? null) === 'done') {
            $urls = [];
            for ($i = 1; $i <= self::COUNT; $i++) {
                if (Storage::exists(self::DIR . "/jobs/{$jobId}/sticker_{$i}.png")) {
                    $urls[] = url("/api/stickers/image/{$jobId}/{$i}");
                }
            }
            $data['stickers'] = $urls;
        }

        return response()->json($data);
    }

    /** Serve one finished sticker PNG. */
    public function image(string $jobId, int $n): BinaryFileResponse|JsonResponse
    {
        $jobId = $this->safeId($jobId);
        $n     = max(1, min(self::COUNT, $n));
        $rel   = self::DIR . "/jobs/{$jobId}/sticker_{$n}.png";
        if (! $jobId || ! Storage::exists($rel)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->file(Storage::path($rel), [
            'Content-Type'        => 'image/png',
            'Content-Disposition' => "inline; filename=\"sticker_{$n}.png\"",
        ]);
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * Bump the cumulative + today's render counter so the admin dashboard can
     * show traffic for this feature. Re-reads fresh config to limit clobbering
     * a concurrent admin save.
     */
    private function recordUse(): void
    {
        $c = $this->config();
        $today = now()->toDateString();
        $u = is_array($c['usage'] ?? null) ? $c['usage'] : [];
        $u['total'] = (int) ($u['total'] ?? 0) + 1;
        if (($u['date'] ?? null) !== $today) {
            $u['date']  = $today;
            $u['today'] = 0;
        }
        $u['today'] = (int) ($u['today'] ?? 0) + 1;
        $c['usage'] = $u;

        Storage::put(self::CONFIG, json_encode($c));
        @chmod(Storage::path(self::CONFIG), 0664);
    }

    /** Short, de-duplicated lyric lines drawn from the Father's Day songs. */
    private function lyricSuggestions(): array
    {
        $out = [];
        foreach ($this->songLyrics() as $song) {
            foreach ($song['lines'] as $line) {
                $out[$line] = true;
            }
        }
        return array_slice(array_keys($out), 0, 40);
    }

    /**
     * Lyric lines grouped by song: [{title, lines:[…]}], so the visitor can pick
     * a song first, then a line. Pulled from the Father's Day song library.
     */
    private function songLyrics(): array
    {
        $rel = 'fathersday/config.json';
        if (! Storage::exists($rel)) {
            return [];
        }
        $c = json_decode((string) Storage::get($rel), true) ?: [];
        $songs = [];
        foreach ($c['songs'] ?? [] as $song) {
            $lines = [];
            foreach (preg_split('/\r?\n/', (string) ($song['lyrics'] ?? '')) as $line) {
                $line = trim(preg_replace('/\[[^\]]*\]/', '', $line)); // drop [tags]/[mm:ss]
                if ($line !== '' && mb_strlen($line) <= 40) {
                    $lines[$line] = true;   // de-dupe within the song
                }
            }
            $lines = array_slice(array_keys($lines), 0, 60);
            if ($lines) {
                $songs[] = [
                    'title' => (string) ($song['title'] ?? 'Song'),
                    'lines' => $lines,
                ];
            }
        }
        return $songs;
    }

    private function runPython(array $args, int $timeout): string
    {
        $python = base_path('../workers/.venv/bin/python');
        $script = base_path('../workers/tools/sticker_render.py');
        $p = new Process(array_merge([$python, $script], $args));
        $p->setTimeout($timeout);
        $p->run();
        if (! $p->isSuccessful()) {
            \Log::warning('Sticker python failed: ' . trim($p->getErrorOutput()));
            return '';
        }
        return trim($p->getOutput());
    }

    private function pruneStale(): void
    {
        $base = Storage::path(self::DIR . '/jobs');
        foreach (glob("{$base}/*") ?: [] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $age      = time() - filemtime($dir);
            $finished = glob("{$dir}/sticker_*.png") !== [];
            // Keep finished stickers ~1 year (shared links stay alive); drop
            // abandoned uploads (no sticker produced) after 1h.
            $ttl = $finished ? self::KEEP_SECS : self::ABANDON_SECS;
            if ($age > $ttl) {
                $this->rrmdir($dir);
            }
        }
    }

    private function safeId(string $id): ?string
    {
        return preg_match('/^[0-9a-f-]{36}$/i', $id) ? $id : null;
    }

    /** Total bytes under the feature's storage tree (for the hard ceiling). */
    private function featureBytes(): int
    {
        $base = Storage::path(self::DIR);
        if (! is_dir($base)) {
            return 0;
        }
        $bytes = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $bytes += $f->getSize();
            }
        }
        return $bytes;
    }

    /**
     * The feature's base dirs are created by the web user (private 0700). The
     * render worker runs as a different OS user in the shared www-data group, so
     * open + setgid them (02775) once: group can traverse and new children
     * inherit the group — mirrors the fathersday tree.
     */
    private function ensureBasePerms(): void
    {
        // The Laravel local-disk root (storage/app/private) is created 0700 by the
        // web user, which blocks the render worker (different user, www-data group)
        // from even traversing INTO the stickers tree below — so it can't read
        // input.json or write status.json and the job hangs at "Queued". Add group
        // TRAVERSE (g+x) only, preserving every other bit (least privilege: the
        // private root stays unreadable/unwritable to the group and closed to all).
        $root = dirname(Storage::path(self::DIR));
        if (is_dir($root)) {
            @chmod($root, (fileperms($root) & 07777) | 0010);
        }

        foreach ([self::DIR, self::DIR . '/jobs'] as $rel) {
            if (Storage::exists($rel)) {
                @chmod(Storage::path($rel), 02775);
            }
        }
    }

    private function openPerms(string $path): void
    {
        @chmod($path, 0775);
        foreach (glob(rtrim($path, '/') . '/*') ?: [] as $child) {
            is_dir($child) ? $this->openPerms($child) : @chmod($child, 0664);
        }
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
