<?php

namespace App\Http\Controllers;

/**
 * ============================================================================
 *  Father's Day (Special Day) Music-Video generator — SELF-CONTAINED & REMOVABLE
 * ============================================================================
 * A standalone feature that lets a visitor pick one of several admin-provided
 * songs, upload photo(s) of their father, and download a vertical 720x1280 MP4
 * set to that song + its lyrics.
 *
 * Nothing here touches the worship pipeline. To remove the whole feature:
 *   1. delete this controller + App\Jobs\RenderFathersDayJob + DetectVocalStartJob
 *   2. delete the "Father's Day MV" route blocks in routes/api.php
 *   3. delete storage/app/fathersday/
 *   4. delete frontend FathersDay.vue + FathersDayManager.vue + their wiring
 *
 * Config + admin-uploaded assets live in storage/app/fathersday/ as plain files
 * (config.json + songs/<songId>.<ext>) — no DB migration. Each song carries its
 * own lyrics, sync mode and detected vocal-onset. Renders run on the dedicated
 * 'fathersday' queue.
 */

use App\Jobs\DetectVocalStartJob;
use App\Jobs\RenderFathersDayJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FathersDayController extends Controller
{
    private const DIR        = 'fathersday';
    private const CONFIG     = 'fathersday/config.json';
    private const MAX_PHOTOS = 6;
    private const MAX_SONGS  = 20;
    private const EFFECTS    = ['slide', 'fade', 'kenburns'];

    // ---- Config helpers ----------------------------------------------------

    private function config(): array
    {
        $defaults = [
            'enabled'        => false,
            'title'          => 'Happy Father\'s Day',
            'subtitle'       => 'Make a music video for your father',
            'default_effect' => 'slide',
            'songs'          => [],   // [{id,title,ext,lyrics,sync_enabled,vocal_start,vocal_start_status}]
            'updated_at'     => null,
        ];

        $data = Storage::exists(self::CONFIG)
            ? json_decode((string) Storage::get(self::CONFIG), true)
            : [];
        $config = is_array($data) ? array_merge($defaults, $data) : $defaults;
        $config = $this->migrateLegacy($config);
        $this->healSongPerms();

        return $config;
    }

    /**
     * Keep the songs dir + files readable by the render worker (a different OS
     * user in the shared www-data group). Storage::makeDirectory/move create them
     * private (0700/0600); open dir to 0775 and files to 0664. Idempotent + cheap;
     * runs as www-data so it can fix anything this app created.
     */
    private function healSongPerms(): void
    {
        $dir = Storage::path(self::DIR . '/songs');
        if (! is_dir($dir)) {
            return;
        }
        @chmod($dir, 0775);
        foreach (glob($dir . '/*') ?: [] as $f) {
            @chmod($f, 0664);
        }
    }

    /** Convert the old single-song config into a one-entry songs[] library. */
    private function migrateLegacy(array $c): array
    {
        if (! empty($c['songs']) || empty($c['song_ext'])) {
            return $c;
        }
        $id  = (string) Str::uuid();
        $ext = $c['song_ext'];
        $from = self::DIR . "/song.{$ext}";
        if (Storage::exists($from)) {
            Storage::makeDirectory(self::DIR . '/songs');
            Storage::move($from, self::DIR . "/songs/{$id}.{$ext}");
        }
        $c['songs'] = [[
            'id'                 => $id,
            'title'              => $c['title'] ?? 'Song 1',
            'ext'                => $ext,
            'lyrics'             => $c['lyrics'] ?? '',
            'sync_enabled'       => (bool) ($c['sync_enabled'] ?? false),
            'vocal_start'        => (float) ($c['vocal_start'] ?? 0.0),
            'vocal_start_status' => $c['vocal_start_status'] ?? 'none',
        ]];
        unset($c['song_ext'], $c['lyrics'], $c['sync_enabled'], $c['vocal_start'], $c['vocal_start_status']);
        $this->saveConfig($c);

        return $c;
    }

    private function saveConfig(array $config): void
    {
        $config['updated_at'] = now()->toIso8601String();
        Storage::put(self::CONFIG, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        @chmod(Storage::path(self::CONFIG), 0664);
    }

    private function findSong(array $c, ?string $id): ?array
    {
        foreach ($c['songs'] as $s) {
            if (($s['id'] ?? null) === $id) {
                return $s;
            }
        }
        return null;
    }

    // ---- Public ------------------------------------------------------------

    /** What the public page needs: enabled flag, effects, and the song menu. */
    public function publicConfig(): JsonResponse
    {
        $c = $this->config();
        $songs = array_values(array_map(
            fn ($s) => ['id' => $s['id'], 'title' => $s['title']],
            array_filter($c['songs'], fn ($s) => ! empty($s['ext']))
        ));

        return response()->json([
            'enabled'        => (bool) $c['enabled'] && count($songs) > 0,
            'title'          => $c['title'],
            'subtitle'       => $c['subtitle'],
            'default_effect' => in_array($c['default_effect'], self::EFFECTS, true) ? $c['default_effect'] : 'slide',
            'effects'        => self::EFFECTS,
            'max_photos'     => self::MAX_PHOTOS,
            'songs'          => $songs,
        ]);
    }

    /** Accept uploaded photo(s) + an effect + chosen song, queue a render. */
    public function render(Request $request): JsonResponse
    {
        $c = $this->config();
        if (! $c['enabled'] || count($c['songs']) === 0) {
            return response()->json(['message' => 'This feature is not available right now.'], 404);
        }

        $validated = $request->validate([
            'photos'   => ['required', 'array', 'min:1', 'max:' . self::MAX_PHOTOS],
            'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'effect'   => ['nullable', 'in:' . implode(',', self::EFFECTS)],
            'song_id'  => ['nullable', 'string'],
        ]);

        // Default to the first song when the client doesn't specify one.
        $song = $this->findSong($c, $validated['song_id'] ?? null) ?? $c['songs'][0];
        if (empty($song['ext'])) {
            return response()->json(['message' => 'Selected song is unavailable.'], 422);
        }

        $effect = $validated['effect'] ?? $c['default_effect'];
        $jobId  = (string) Str::uuid();
        $jobDir = self::DIR . "/jobs/{$jobId}";

        // Store each upload re-named (never trust client filenames). The render
        // job re-encodes every image through ffmpeg, which strips EXIF/GPS and
        // neutralises malformed-image payloads.
        $i = 0;
        foreach ($request->file('photos') as $photo) {
            $ext = strtolower($photo->getClientOriginalExtension() ?: 'jpg');
            $ext = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? $ext : 'jpg';
            $photo->storeAs("{$jobDir}/src", sprintf('photo_%02d.%s', $i++, $ext));
        }

        Storage::put("{$jobDir}/status.json", json_encode([
            'status'     => 'queued',
            'progress'   => 0,
            'stage'      => 'Queued',
            'effect'     => $effect,
            'song_id'    => $song['id'],
            'created_at' => now()->toIso8601String(),
        ]));

        // www-data creates these private; the worker (other user, shared group)
        // must read the photos and rewrite the status — open the tree.
        $this->openPerms(Storage::path($jobDir));

        RenderFathersDayJob::dispatch($jobId, $effect, $song['id']);

        return response()->json(['job_id' => $jobId, 'status' => 'queued']);
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
            $data['download_url'] = url("/api/fathers-day/download/{$jobId}");
        }

        return response()->json($data);
    }

    public function download(string $jobId): BinaryFileResponse|JsonResponse
    {
        $jobId = $this->safeId($jobId);
        $rel   = self::DIR . "/jobs/{$jobId}/output.mp4";
        if (! $jobId || ! Storage::exists($rel)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->download(Storage::path($rel), 'fathers-day.mp4', [
            'Content-Type' => 'video/mp4',
        ]);
    }

    // ---- Admin: global settings -------------------------------------------

    public function adminShow(): JsonResponse
    {
        $c = $this->config();

        return response()->json([
            'enabled'        => (bool) $c['enabled'],
            'title'          => $c['title'],
            'subtitle'       => $c['subtitle'],
            'default_effect' => $c['default_effect'],
            'songs'          => array_values(array_map(fn ($s) => [
                'id'                 => $s['id'],
                'title'              => $s['title'],
                'has_song'           => ! empty($s['ext']),
                'ext'                => $s['ext'] ?? null,
                'lyrics'             => $s['lyrics'] ?? '',
                'sync_enabled'       => (bool) ($s['sync_enabled'] ?? false),
                'vocal_start'        => (float) ($s['vocal_start'] ?? 0.0),
                'vocal_start_status' => $s['vocal_start_status'] ?? 'none',
            ], $c['songs'])),
            'updated_at'     => $c['updated_at'],
        ]);
    }

    public function adminSave(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled'        => ['required', 'boolean'],
            'title'          => ['nullable', 'string', 'max:120'],
            'subtitle'       => ['nullable', 'string', 'max:200'],
            'default_effect' => ['required', 'in:' . implode(',', self::EFFECTS)],
        ]);

        $c = $this->config();
        $c['enabled']        = $validated['enabled'];
        $c['title']          = $validated['title'] ?: 'Happy Father\'s Day';
        $c['subtitle']       = $validated['subtitle'] ?: 'Make a music video for your father';
        $c['default_effect'] = $validated['default_effect'];
        $this->saveConfig($c);

        return $this->adminShow();
    }

    // ---- Admin: song library ----------------------------------------------

    /** Add a song to the library (upload file + title), then detect its vocals. */
    public function createSong(Request $request): JsonResponse
    {
        $request->validate([
            'song'  => ['required', 'file', 'mimes:mp3,wav', 'max:51200'],
            'title' => ['nullable', 'string', 'max:120'],
        ]);

        $c = $this->config();
        if (count($c['songs']) >= self::MAX_SONGS) {
            return response()->json(['message' => 'Song limit reached.'], 422);
        }

        $id  = (string) Str::uuid();
        $ext = strtolower($request->file('song')->getClientOriginalExtension() ?: 'mp3');
        $ext = in_array($ext, ['mp3', 'wav'], true) ? $ext : 'mp3';
        $request->file('song')->storeAs(self::DIR . '/songs', "{$id}.{$ext}");
        @chmod(Storage::path(self::DIR . "/songs/{$id}.{$ext}"), 0664);

        $c['songs'][] = [
            'id'                 => $id,
            'title'              => $request->input('title') ?: ('Song ' . (count($c['songs']) + 1)),
            'ext'                => $ext,
            'lyrics'             => '',
            'sync_enabled'       => false,
            'vocal_start'        => 0.0,
            'vocal_start_status' => 'detecting',
        ];
        $this->saveConfig($c);

        DetectVocalStartJob::dispatch($id);

        return $this->adminShow();
    }

    /** Update a song's title / lyrics / sync mode / vocal-onset override. */
    public function updateSong(Request $request, string $songId): JsonResponse
    {
        $validated = $request->validate([
            'title'        => ['sometimes', 'string', 'max:120'],
            'lyrics'       => ['sometimes', 'nullable', 'string', 'max:20000'],
            'sync_enabled' => ['sometimes', 'boolean'],
            'vocal_start'  => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:600'],
        ]);

        $c = $this->config();
        $found = false;
        foreach ($c['songs'] as &$s) {
            if ($s['id'] !== $songId) {
                continue;
            }
            $found = true;
            if (array_key_exists('title', $validated) && $validated['title'] !== null) {
                $s['title'] = $validated['title'];
            }
            if (array_key_exists('lyrics', $validated)) {
                $s['lyrics'] = $validated['lyrics'] ?? '';
            }
            if (array_key_exists('sync_enabled', $validated)) {
                $s['sync_enabled'] = (bool) $validated['sync_enabled'];
            }
            if (array_key_exists('vocal_start', $validated) && $validated['vocal_start'] !== null) {
                $s['vocal_start']        = (float) $validated['vocal_start'];
                $s['vocal_start_status'] = 'ready';
            }
            break;
        }
        unset($s);

        if (! $found) {
            return response()->json(['message' => 'Song not found'], 404);
        }
        $this->saveConfig($c);

        return $this->adminShow();
    }

    public function deleteSong(string $songId): JsonResponse
    {
        $c = $this->config();
        $song = $this->findSong($c, $songId);
        if (! $song) {
            return response()->json(['message' => 'Song not found'], 404);
        }
        if (! empty($song['ext'])) {
            Storage::delete(self::DIR . "/songs/{$songId}.{$song['ext']}");
        }
        $c['songs'] = array_values(array_filter($c['songs'], fn ($s) => $s['id'] !== $songId));
        $this->saveConfig($c);

        return $this->adminShow();
    }

    /** Stream a library song to the admin tap-to-sync player. */
    public function adminSong(string $songId): BinaryFileResponse|JsonResponse
    {
        $song = $this->findSong($this->config(), $songId);
        if (! $song || empty($song['ext'])) {
            return response()->json(['message' => 'No song'], 404);
        }
        $rel = self::DIR . "/songs/{$songId}.{$song['ext']}";
        if (! Storage::exists($rel)) {
            return response()->json(['message' => 'No song'], 404);
        }

        return response()->file(Storage::path($rel), [
            'Content-Type' => $song['ext'] === 'wav' ? 'audio/wav' : 'audio/mpeg',
        ]);
    }

    // ---- Guards ------------------------------------------------------------

    private function safeId(string $id): ?string
    {
        return preg_match('/^[0-9a-f-]{36}$/i', $id) ? $id : null;
    }

    /**
     * Recursively open a job tree to the queue worker (a different OS user in the
     * shared www-data group): dirs 0775 (group create/delete temp files), files
     * 0664 (worker rewrites status.json). The tree is ephemeral and not
     * web-exposed, so group/other access is acceptable.
     */
    private function openPerms(string $path): void
    {
        @chmod($path, 0775);
        foreach (glob(rtrim($path, '/') . '/*') ?: [] as $child) {
            is_dir($child) ? $this->openPerms($child) : @chmod($child, 0664);
        }
    }
}
