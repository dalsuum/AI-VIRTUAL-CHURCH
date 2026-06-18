<?php

namespace App\Http\Controllers;

/**
 * ============================================================================
 *  Father's Day (Special Day) Music-Video generator — SELF-CONTAINED & REMOVABLE
 * ============================================================================
 * A standalone feature that lets a visitor upload photo(s) of their father and
 * download a vertical (1080x1920) MP4 set to an admin-provided song + lyrics.
 *
 * Nothing here touches the worship pipeline. To remove the whole feature:
 *   1. delete this controller + App\Jobs\RenderFathersDayJob
 *   2. delete the "Father's Day MV" route block in routes/api.php
 *   3. delete storage/app/fathersday/
 *   4. delete frontend FathersDay.vue + its #fathers-day route + admin section
 *
 * Config + admin-uploaded assets live in storage/app/fathersday/ as plain files
 * (config.json, song.<ext>, lyrics.lrc) — no DB migration. Renders are queued
 * onto the existing Laravel queue worker.
 */

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
    private const EFFECTS    = ['slide', 'fade', 'kenburns'];

    // ---- Config helpers ----------------------------------------------------

    private function config(): array
    {
        $defaults = [
            'enabled'        => false,
            'title'          => 'Happy Father\'s Day',
            'subtitle'       => 'Make a music video for your father',
            'lyrics'         => '',          // plain text or LRC ([mm:ss.xx]) lines
            'sync_enabled'   => false,       // time-synced highlight vs. even split
            'default_effect' => 'slide',
            'song_ext'       => null,        // 'mp3' | 'wav' once a song is uploaded
            'updated_at'     => null,
        ];

        if (! Storage::exists(self::CONFIG)) {
            return $defaults;
        }

        $data = json_decode((string) Storage::get(self::CONFIG), true);

        return is_array($data) ? array_merge($defaults, $data) : $defaults;
    }

    private function saveConfig(array $config): void
    {
        $config['updated_at'] = now()->toIso8601String();
        Storage::put(self::CONFIG, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    // ---- Public ------------------------------------------------------------

    /** What the public page needs to render itself (no lyrics/song bytes leaked unless ready). */
    public function publicConfig(): JsonResponse
    {
        $c = $this->config();

        return response()->json([
            'enabled'        => (bool) $c['enabled'] && $c['song_ext'] !== null,
            'title'          => $c['title'],
            'subtitle'       => $c['subtitle'],
            'default_effect' => in_array($c['default_effect'], self::EFFECTS, true) ? $c['default_effect'] : 'slide',
            'effects'        => self::EFFECTS,
            'max_photos'     => self::MAX_PHOTOS,
        ]);
    }

    /** Accept uploaded photo(s) + an effect, queue a render, return a job id. */
    public function render(Request $request): JsonResponse
    {
        $c = $this->config();
        if (! $c['enabled'] || $c['song_ext'] === null) {
            return response()->json(['message' => 'This feature is not available right now.'], 404);
        }

        $validated = $request->validate([
            'photos'   => ['required', 'array', 'min:1', 'max:' . self::MAX_PHOTOS],
            'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'effect'   => ['nullable', 'in:' . implode(',', self::EFFECTS)],
        ]);

        $effect = $validated['effect'] ?? $c['default_effect'];
        $jobId  = (string) Str::uuid();
        $jobDir = self::DIR . "/jobs/{$jobId}";

        // Store each upload re-named (never trust client filenames). The render
        // job re-encodes every image through ffmpeg, which strips EXIF/GPS and
        // neutralises malformed-image payloads — extension is validated above,
        // content is validated by ffmpeg decoding it.
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
            'created_at' => now()->toIso8601String(),
        ]));

        // The web server (www-data) creates these with private 0700/0600 perms,
        // but the queue worker runs as a different user and must read the photos
        // and rewrite the status. Open the tree to group/other so both can work.
        $this->openPerms(Storage::path($jobDir));

        RenderFathersDayJob::dispatch($jobId, $effect);

        return response()->json(['job_id' => $jobId, 'status' => 'queued']);
    }

    /** Poll render progress. */
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

    /** Stream the finished MP4 as a download. */
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

    // ---- Admin -------------------------------------------------------------

    public function adminShow(): JsonResponse
    {
        $c = $this->config();

        return response()->json([
            'enabled'        => (bool) $c['enabled'],
            'title'          => $c['title'],
            'subtitle'       => $c['subtitle'],
            'lyrics'         => $c['lyrics'],
            'sync_enabled'   => (bool) $c['sync_enabled'],
            'default_effect' => $c['default_effect'],
            'has_song'       => $c['song_ext'] !== null,
            'song_ext'       => $c['song_ext'],
            'updated_at'     => $c['updated_at'],
        ]);
    }

    public function adminSave(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled'        => ['required', 'boolean'],
            'title'          => ['nullable', 'string', 'max:120'],
            'subtitle'       => ['nullable', 'string', 'max:200'],
            'lyrics'         => ['nullable', 'string', 'max:20000'],
            'sync_enabled'   => ['required', 'boolean'],
            'default_effect' => ['required', 'in:' . implode(',', self::EFFECTS)],
        ]);

        $c = $this->config();
        $c['enabled']        = $validated['enabled'];
        $c['title']          = $validated['title'] ?: 'Happy Father\'s Day';
        $c['subtitle']       = $validated['subtitle'] ?: 'Make a music video for your father';
        $c['lyrics']         = $validated['lyrics'] ?? '';
        $c['sync_enabled']   = $validated['sync_enabled'];
        $c['default_effect'] = $validated['default_effect'];
        $this->saveConfig($c);

        return $this->adminShow();
    }

    public function adminUploadSong(Request $request): JsonResponse
    {
        $request->validate([
            'song' => ['required', 'file', 'mimes:mp3,wav', 'max:51200'],
        ]);

        // Remove any prior song so only one canonical track exists.
        foreach (['mp3', 'wav'] as $ext) {
            Storage::delete(self::DIR . "/song.{$ext}");
        }

        $ext = strtolower($request->file('song')->getClientOriginalExtension() ?: 'mp3');
        $ext = in_array($ext, ['mp3', 'wav'], true) ? $ext : 'mp3';
        $request->file('song')->storeAs(self::DIR, "song.{$ext}");

        $c = $this->config();
        $c['song_ext'] = $ext;
        $this->saveConfig($c);

        return $this->adminShow();
    }

    // ---- Guards ------------------------------------------------------------

    /** Job ids are UUIDs; reject anything else so they can't escape the dir. */
    private function safeId(string $id): ?string
    {
        return preg_match('/^[0-9a-f-]{36}$/i', $id) ? $id : null;
    }

    /**
     * Recursively make a job tree readable/traversable by the queue worker (a
     * different OS user). Dirs 0775 (group can create/delete temp files), files
     * 0644. The tree holds only ephemeral photos + status and is deleted after
     * the render, and is never web-exposed, so group/other read is acceptable.
     */
    private function openPerms(string $path): void
    {
        @chmod($path, 0775);
        foreach (glob(rtrim($path, '/') . '/*') ?: [] as $child) {
            is_dir($child) ? $this->openPerms($child) : @chmod($child, 0644);
        }
    }
}
