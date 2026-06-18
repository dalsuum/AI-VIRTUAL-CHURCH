<?php

namespace App\Jobs;

/**
 * Father's Day (Special Day) MV renderer — SELF-CONTAINED & REMOVABLE.
 * Turns the visitor's uploaded photo(s) + the admin song/lyrics into a vertical
 * 1080x1920 MP4 via ffmpeg. Runs on the existing Laravel queue worker.
 *
 * See App\Http\Controllers\FathersDayController for the surrounding feature and
 * removal steps. No worship-pipeline code is touched.
 */

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class RenderFathersDayJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;   // a long song + libx264 can take minutes
    public int $tries   = 1;     // re-encoding is expensive; fail fast

    private const W   = 1080;
    private const H   = 1920;
    private const FPS = 25;
    private const XFADE = 1.0;   // crossfade seconds for the "fade" effect

    public function __construct(public string $jobId, public string $effect) {}

    public function handle(): void
    {
        $dir = Storage::path("fathersday/jobs/{$this->jobId}");
        $src = "{$dir}/src";
        $out = "{$dir}/output.mp4";

        try {
            $this->setStatus('rendering');

            $song = $this->songPath();
            if (! $song) {
                throw new \RuntimeException('No song configured.');
            }

            $photos = glob("{$src}/*");
            sort($photos);
            if (! $photos) {
                throw new \RuntimeException('No photos uploaded.');
            }

            @mkdir("{$dir}/work", 0775, true);
            $work = "{$dir}/work";

            // 1) Normalise every photo to a vertical frame. -map_metadata -1
            //    strips EXIF/GPS; ffmpeg decoding it validates the bytes.
            $frames = [];
            foreach ($photos as $i => $photo) {
                $frame = sprintf('%s/norm_%02d.jpg', $work, $i);
                $this->ffmpeg([
                    '-i', $photo,
                    '-vf', sprintf(
                        'scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,setsar=1',
                        self::W, self::H, self::W, self::H
                    ),
                    '-map_metadata', '-1',
                    '-frames:v', '1',
                    '-q:v', '2',
                    $frame,
                ]);
                $frames[] = $frame;
            }

            $duration = $this->probeDuration($song);

            // 2) Build the silent slideshow video matching the song length.
            $slideshow = "{$work}/slideshow.mp4";
            $this->buildSlideshow($frames, $duration, $slideshow, $work);

            // 3) Burn lyrics (optional) and mux the song in.
            $assRel = $this->buildSubtitles($duration, $work); // relative name or null
            $this->mux($slideshow, $song, $assRel, $work, $out);

            $this->setStatus('done');
        } catch (\Throwable $e) {
            Log::error("FathersDay render {$this->jobId} failed: {$e->getMessage()}");
            $this->setStatus('error', 'Sorry — the video could not be generated. Please try again.');
        } finally {
            // Drop the originals; keep only output.mp4 + status.json.
            $this->rrmdir($src);
            $this->rrmdir("{$dir}/work");
        }
    }

    // ---- Slideshow ---------------------------------------------------------

    private function buildSlideshow(array $frames, float $duration, string $out, string $work): void
    {
        $n = count($frames);

        if ($n === 1) {
            if ($this->effect === 'kenburns') {
                $this->kenburns($frames[0], $duration, $out);
            } else {
                // Single still held for the whole song.
                $this->ffmpeg([
                    '-loop', '1', '-i', $frames[0],
                    '-t', (string) $duration,
                    '-r', (string) self::FPS,
                    '-vf', sprintf('scale=%d:%d,setsar=1,format=yuv420p', self::W, self::H),
                    '-c:v', 'libx264', '-preset', 'veryfast', '-tune', 'stillimage',
                    $out,
                ]);
            }
            return;
        }

        if ($this->effect === 'fade') {
            $this->xfadeSlideshow($frames, $duration, $out);
            return;
        }

        if ($this->effect === 'kenburns') {
            $this->kenburnsSlideshow($frames, $duration, $out, $work);
            return;
        }

        // Default: hard-cut slideshow via the concat demuxer.
        $per  = $duration / $n;
        $list = "{$work}/list.txt";
        $lines = '';
        foreach ($frames as $f) {
            $lines .= "file '" . str_replace("'", "'\\''", $f) . "'\n";
            $lines .= 'duration ' . number_format($per, 3, '.', '') . "\n";
        }
        // concat demuxer needs the final image repeated to honour its duration.
        $lines .= "file '" . str_replace("'", "'\\''", end($frames)) . "'\n";
        file_put_contents($list, $lines);

        $this->ffmpeg([
            '-f', 'concat', '-safe', '0', '-i', $list,
            '-t', (string) $duration,
            '-r', (string) self::FPS,
            '-vf', sprintf('scale=%d:%d,setsar=1,format=yuv420p', self::W, self::H),
            '-c:v', 'libx264', '-preset', 'veryfast',
            $out,
        ]);
    }

    private function kenburns(string $frame, float $duration, string $out): void
    {
        $d = (int) ceil($duration * self::FPS);
        $this->ffmpeg([
            '-loop', '1', '-i', $frame,
            '-t', (string) $duration,
            '-r', (string) self::FPS,
            '-vf', sprintf(
                'scale=%d:%d,zoompan=z=\'min(zoom+0.0006,1.3)\':d=%d:s=%dx%d:fps=%d,setsar=1,format=yuv420p',
                self::W * 2, self::H * 2, $d, self::W, self::H, self::FPS
            ),
            '-c:v', 'libx264', '-preset', 'veryfast',
            $out,
        ]);
    }

    private function kenburnsSlideshow(array $frames, float $duration, string $out, string $work): void
    {
        $n   = count($frames);
        $per = $duration / $n;
        $segs = [];
        foreach ($frames as $i => $f) {
            $seg = sprintf('%s/seg_%02d.mp4', $work, $i);
            $this->kenburns($f, $per, $seg);
            $segs[] = $seg;
        }
        $list = "{$work}/seglist.txt";
        $lines = '';
        foreach ($segs as $s) {
            $lines .= "file '" . str_replace("'", "'\\''", $s) . "'\n";
        }
        file_put_contents($list, $lines);
        $this->ffmpeg([
            '-f', 'concat', '-safe', '0', '-i', $list,
            '-c', 'copy',
            $out,
        ]);
    }

    private function xfadeSlideshow(array $frames, float $duration, string $out): void
    {
        $n = count($frames);
        $t = self::XFADE;
        // Each clip length so total after (n-1) overlaps equals the song length.
        $per = ($duration + ($n - 1) * $t) / $n;

        $args = [];
        foreach ($frames as $f) {
            $args[] = '-loop';
            $args[] = '1';
            $args[] = '-t';
            $args[] = number_format($per, 3, '.', '');
            $args[] = '-i';
            $args[] = $f;
        }

        // Normalise every input, then chain xfade transitions.
        $fc = '';
        for ($i = 0; $i < $n; $i++) {
            $fc .= sprintf(
                '[%d:v]scale=%d:%d,setsar=1,fps=%d,format=yuv420p[v%d];',
                $i, self::W, self::H, self::FPS, $i
            );
        }
        $prev   = 'v0';
        $offset = $per - $t;
        for ($i = 1; $i < $n; $i++) {
            $label = ($i === $n - 1) ? 'vout' : "x{$i}";
            $fc .= sprintf(
                '[%s][v%d]xfade=transition=fade:duration=%s:offset=%s[%s];',
                $prev, $i, number_format($t, 3, '.', ''), number_format($offset, 3, '.', ''), $label
            );
            $prev = $label;
            $offset += $per - $t;
        }
        $fc = rtrim($fc, ';');

        $this->ffmpeg(array_merge($args, [
            '-filter_complex', $fc,
            '-map', '[vout]',
            '-t', (string) $duration,
            '-r', (string) self::FPS,
            '-c:v', 'libx264', '-preset', 'veryfast',
            $out,
        ]));
    }

    // ---- Lyrics (ASS subtitles) -------------------------------------------

    /** Returns the relative .ass filename (rendered with cwd=$work) or null. */
    private function buildSubtitles(float $duration, string $work): ?string
    {
        $c      = $this->config();
        $lyrics = trim((string) ($c['lyrics'] ?? ''));
        if ($lyrics === '') {
            return null;
        }

        $cues = ($c['sync_enabled'] ?? false)
            ? $this->parseLrc($lyrics, $duration)
            : [];

        if (! $cues) {
            // Even-split fallback (also covers static mode and malformed LRC).
            $lines = preg_split('/\r?\n/', $lyrics);
            // Drop section markers ([Verse 1], [Chorus], [Bridge]…) — a line that is
            // wholly a bracketed tag. Then strip any inline [mm:ss.xx] timestamps.
            $lines = array_filter(array_map('trim', $lines), fn ($l) => $l !== '' && ! preg_match('/^\[[^\]]*\]$/', $l));
            $lines = array_map(fn ($l) => trim(preg_replace('/\[[0-9:.]+\]/', '', $l)), $lines);
            $lines = array_values(array_filter($lines, fn ($l) => $l !== ''));
            if (! $lines) {
                return null;
            }
            $per = $duration / count($lines);
            foreach ($lines as $i => $text) {
                $cues[] = ['start' => $i * $per, 'end' => ($i + 1) * $per, 'text' => $text];
            }
        }

        $ass = $this->assHeader();
        foreach ($cues as $cue) {
            $ass .= sprintf(
                "Dialogue: 0,%s,%s,Lyric,,0,0,0,,%s\n",
                $this->assTime($cue['start']),
                $this->assTime($cue['end']),
                $this->assEscape($cue['text'])
            );
        }

        $name = 'subs.ass';
        file_put_contents("{$work}/{$name}", $ass);

        return $name;
    }

    /** Parse [mm:ss.xx] LRC lines into ordered cues; [] returns empty on failure. */
    private function parseLrc(string $lyrics, float $duration): array
    {
        $stamped = [];
        foreach (preg_split('/\r?\n/', $lyrics) as $line) {
            if (! preg_match_all('/\[(\d+):(\d+)(?:\.(\d+))?\]/', $line, $m, PREG_SET_ORDER)) {
                continue;
            }
            $text = trim(preg_replace('/\[[0-9:.]+\]/', '', $line));
            if ($text === '') {
                continue;
            }
            foreach ($m as $stamp) {
                $sec = ((int) $stamp[1]) * 60 + (int) $stamp[2]
                     + (isset($stamp[3]) && $stamp[3] !== '' ? (float) ('0.' . $stamp[3]) : 0.0);
                $stamped[] = ['start' => $sec, 'text' => $text];
            }
        }
        if (! $stamped) {
            return [];
        }
        usort($stamped, fn ($a, $b) => $a['start'] <=> $b['start']);

        $cues = [];
        foreach ($stamped as $i => $s) {
            $end = $stamped[$i + 1]['start'] ?? $duration;
            $cues[] = ['start' => $s['start'], 'end' => max($end, $s['start'] + 0.5), 'text' => $s['text']];
        }
        return $cues;
    }

    private function assHeader(): string
    {
        return "[Script Info]\nScriptType: v4.00+\nPlayResX: " . self::W . "\nPlayResY: " . self::H . "\nWrapStyle: 0\n\n"
            . "[V4+ Styles]\n"
            . "Format: Name, Fontname, Fontsize, PrimaryColour, OutlineColour, BackColour, Bold, Alignment, MarginL, MarginR, MarginV, Outline, Shadow, BorderStyle\n"
            // White text, black outline, large, centered near the lower third.
            // Myanmar Njaun (bundled in resources/fonts) renders Myanmar + Latin so
            // EN/MY/TD lyrics all display; the host has no Myanmar system font.
            . "Style: Lyric,Myanmar Njaun,60,&H00FFFFFF,&H00000000,&H64000000,1,2,80,80,260,3,1,1\n\n"
            . "[Events]\n"
            . "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
    }

    private function assTime(float $s): string
    {
        $s = max(0.0, $s);
        $h = (int) floor($s / 3600);
        $m = (int) floor(($s - $h * 3600) / 60);
        $sec = $s - $h * 3600 - $m * 60;
        return sprintf('%d:%02d:%05.2f', $h, $m, $sec);
    }

    private function assEscape(string $t): string
    {
        // ASS line break + brace escaping; keep it single-line.
        $t = str_replace(['{', '}'], ['(', ')'], $t);
        return str_replace(["\r", "\n"], ['', ' '], $t);
    }

    // ---- Final mux ---------------------------------------------------------

    private function mux(string $slideshow, string $song, ?string $assRel, string $work, string $out): void
    {
        $args = ['-i', $slideshow, '-i', $song];
        if ($assRel !== null) {
            // Point libass at the bundled Padauk font so Myanmar (and Latin) lyrics
            // render — the host has no Myanmar system font.
            $fontsDir = base_path('resources/fonts');
            $args[] = '-vf';
            $args[] = "ass={$assRel}:fontsdir={$fontsDir}";
        }
        $args = array_merge($args, [
            '-map', '0:v:0', '-map', '1:a:0',
            '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '192k',
            '-shortest', '-movflags', '+faststart',
            $out,
        ]);

        // cwd=$work so the relative ass path resolves without filter-escaping pain.
        $this->ffmpeg($args, $work);
    }

    // ---- Helpers -----------------------------------------------------------

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

    private function probeDuration(string $file): float
    {
        $p = new Process([
            'ffprobe', '-v', 'error', '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1', $file,
        ]);
        $p->run();
        $d = (float) trim($p->getOutput());
        // Clamp to a sane range so a corrupt header can't produce a 3-hour render.
        return max(5.0, min($d ?: 60.0, 600.0));
    }

    private function ffmpeg(array $args, ?string $cwd = null): void
    {
        $p = new Process(array_merge(['ffmpeg', '-y', '-hide_banner', '-loglevel', 'error'], $args), $cwd);
        $p->setTimeout($this->timeout);
        $p->run();
        if (! $p->isSuccessful()) {
            throw new \RuntimeException('ffmpeg failed: ' . trim($p->getErrorOutput()));
        }
    }

    private function config(): array
    {
        $rel = 'fathersday/config.json';
        if (! Storage::exists($rel)) {
            return [];
        }
        return json_decode((string) Storage::get($rel), true) ?: [];
    }

    private function setStatus(string $status, ?string $message = null): void
    {
        $rel  = "fathersday/jobs/{$this->jobId}/status.json";
        $data = ['status' => $status, 'updated_at' => now()->toIso8601String()];
        if ($message) {
            $data['message'] = $message;
        }
        Storage::put($rel, json_encode($data));
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
