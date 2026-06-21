<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Manages the Bible reader's background-music library: admin-uploaded tracks
 * plus the AI-generated per-theme/time-of-day loops. Uploaded tracks live under
 * storage/app/bible/bg-music/uploads/<id>.<ext> with a small JSON manifest;
 * AI tracks are discovered by scanning the public bible-bg/ dir the worker fills.
 *
 * Every track is served back through BibleController@bgMusicFile via an opaque
 * (src, key) pair, never a client path, so there's no traversal surface.
 */
class BibleBgMusicLibrary
{
    /** Uploaded-track storage (relative to storage/app). */
    public const UPLOAD_DIR = 'bible/bg-music/uploads';
    public const MANIFEST   = 'bible/bg-music/library.json';
    /** Where the Celery worker writes generated loops (public disk). */
    public const AI_DIR = 'public/bible-bg';

    /** All library tracks: uploaded first (newest first), then AI loops (A→Z). */
    public function all(): array
    {
        return array_merge($this->uploads(), $this->aiTracks());
    }

    /** @return array<int, array{id:string,title:string,source:string,ext:string,url:string,key:string}> */
    public function uploads(): array
    {
        $out = [];
        foreach ($this->manifest() as $t) {
            $out[] = [
                'id'     => $t['id'],
                'title'  => $t['title'] ?? $t['id'],
                'source' => 'upload',
                'ext'    => $t['ext'] ?? 'mp3',
                'key'    => $t['id'],
                'url'    => $this->url('upload', $t['id']),
            ];
        }
        // Newest first.
        return array_reverse($out);
    }

    /** AI-generated loops the worker has cached, as read-only selectable tracks. */
    public function aiTracks(): array
    {
        $dir = Storage::path(self::AI_DIR);
        if (! is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (glob($dir . '/*.mp3') ?: [] as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);     // e.g. comfort_morning
            if (! preg_match('/^[a-z]+_[a-z]+$/', $name)) {
                continue;
            }
            $out[] = [
                'id'     => "ai:{$name}",
                'title'  => 'AI · ' . ucwords(str_replace('_', ' ', $name)),
                'source' => 'ai',
                'ext'    => 'mp3',
                'key'    => $name,
                'url'    => $this->url('ai', $name),
            ];
        }
        sort($out);
        usort($out, fn ($a, $b) => strcmp($a['title'], $b['title']));

        return $out;
    }

    /** Store a freshly uploaded track; returns its library entry. */
    public function addUpload(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $ext = in_array($ext, ['ogg', 'oga'], true) ? 'ogg' : 'mp3';
        $id  = (string) Str::uuid();

        $file->storeAs(self::UPLOAD_DIR, "{$id}.{$ext}");
        @chmod(Storage::path(self::UPLOAD_DIR . "/{$id}.{$ext}"), 0664);

        $title = $this->cleanTitle($file->getClientOriginalName()) ?: 'Track';
        $manifest = $this->manifest();
        $manifest[] = [
            'id'         => $id,
            'title'      => $title,
            'ext'        => $ext,
            'created_at' => now()->toIso8601String(),
        ];
        $this->saveManifest($manifest);

        return [
            'id'     => $id,
            'title'  => $title,
            'source' => 'upload',
            'ext'    => $ext,
            'key'    => $id,
            'url'    => $this->url('upload', $id),
        ];
    }

    /** Delete an uploaded track (file + manifest entry). AI tracks are read-only. */
    public function deleteUpload(string $id): ?array
    {
        $manifest = $this->manifest();
        $removed = null;
        $kept = [];
        foreach ($manifest as $t) {
            if ($t['id'] === $id) {
                $removed = $t;
            } else {
                $kept[] = $t;
            }
        }
        if ($removed === null) {
            return null;
        }
        Storage::delete(self::UPLOAD_DIR . "/{$id}." . ($removed['ext'] ?? 'mp3'));
        $this->saveManifest($kept);

        return ['url' => $this->url('upload', $id)];
    }

    /** Absolute filesystem path for a (src,key) pair, or null if it doesn't exist. */
    public function resolvePath(string $src, string $key): ?string
    {
        if ($src === 'upload') {
            if (! preg_match('/^[a-f0-9-]{36}$/', $key)) {
                return null;
            }
            foreach (['mp3', 'ogg'] as $ext) {
                $rel = self::UPLOAD_DIR . "/{$key}.{$ext}";
                if (Storage::exists($rel)) {
                    return Storage::path($rel);
                }
            }

            return null;
        }
        if ($src === 'ai') {
            if (! preg_match('/^[a-z]+_[a-z]+$/', $key)) {
                return null;
            }
            $rel = self::AI_DIR . "/{$key}.mp3";

            return Storage::exists($rel) ? Storage::path($rel) : null;
        }

        return null;
    }

    /** Public serving URL for a track, used as bible_bg_music_url when selected. */
    public function url(string $src, string $key): string
    {
        return url('/api/bible/bg-music/file') . '?' . http_build_query(['src' => $src, 'key' => $key]);
    }

    // --- internals -------------------------------------------------------

    private function manifest(): array
    {
        if (! Storage::exists(self::MANIFEST)) {
            return [];
        }
        $data = json_decode((string) Storage::get(self::MANIFEST), true);

        return is_array($data) ? array_values(array_filter($data, fn ($t) => ! empty($t['id']))) : [];
    }

    private function saveManifest(array $manifest): void
    {
        Storage::put(self::MANIFEST, json_encode(array_values($manifest), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        @chmod(Storage::path(self::MANIFEST), 0664);
    }

    private function cleanTitle(string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $base = preg_replace('/[_\-]+/', ' ', $base);

        return trim(Str::limit((string) $base, 80, ''));
    }
}
