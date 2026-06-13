<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use ZipArchive;

class VoiceStudioController extends Controller
{
    private function langCode(string $lang): string
    {
        return match ($lang) {
            'tedim', 'td' => 'td',
            'burmese', 'my' => 'my',
            default => abort(404, 'Unknown language'),
        };
    }

    private function scriptPath(string $lang): string
    {
        return resource_path("voice-studio/{$lang}_script.json");
    }

    private function recordingsDir(string $lang): string
    {
        return storage_path("app/voice-studio/" . auth()->id() . "/{$lang}/");
    }

    private function manifestPath(string $lang): string
    {
        return storage_path("app/voice-studio/" . auth()->id() . "/{$lang}/manifest.json");
    }

    private function loadManifest(string $lang): array
    {
        $path = $this->manifestPath($lang);
        if (!file_exists($path)) {
            return [];
        }
        return json_decode(file_get_contents($path), true) ?? [];
    }

    private function saveManifest(string $lang, array $manifest): void
    {
        $dir = $this->recordingsDir($lang);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($this->manifestPath($lang), json_encode($manifest, JSON_UNESCAPED_UNICODE));
    }

    public function script(string $lang)
    {
        $lang = $this->langCode($lang);
        $path = $this->scriptPath($lang);

        if (!file_exists($path)) {
            return response()->json(['error' => 'Script not found. Contact admin.'], 404);
        }

        $sentences = json_decode(file_get_contents($path), true);
        $manifest  = $this->loadManifest($lang);
        $recordedIds = array_column($manifest, 'id');

        return response()->json([
            'sentences'    => $sentences,
            'recorded_ids' => $recordedIds,
        ]);
    }

    public function store(Request $req)
    {
        $req->validate([
            'lang'  => 'required|in:td,my',
            'id'    => 'required|integer|min:1',
            'text'  => 'required|string|max:500',
            'audio' => 'required|file|max:10240',
        ]);

        $lang  = $req->input('lang');
        $id    = (int) $req->input('id');
        $text  = $req->input('text');
        $audio = $req->file('audio');

        $dir = $this->recordingsDir($lang);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $tmpIn   = $audio->getRealPath();
        $outFile = sprintf('%s%04d.wav', $dir, $id);

        // Convert browser audio (webm/ogg/wav) → 16 kHz mono PCM WAV and trim
        // leading/trailing silence. The double silenceremove+areverse removes both
        // ends: first pass strips the front, areverse flips the file, second pass
        // strips the new front (original end), areverse restores direction.
        // -45 dB threshold catches room noise without clipping soft speech.
        $cmd = sprintf(
            'ffmpeg -y -i %s -ar 16000 -ac 1'
            . ' -af "silenceremove=start_periods=1:start_threshold=-45dB:start_duration=0.1,'
            .       'areverse,'
            .       'silenceremove=start_periods=1:start_threshold=-45dB:start_duration=0.1,'
            .       'areverse"'
            . ' -acodec pcm_s16le %s 2>&1',
            escapeshellarg($tmpIn),
            escapeshellarg($outFile)
        );
        exec($cmd, $output, $code);

        if ($code !== 0) {
            return response()->json([
                'error'  => 'Audio conversion failed',
                'detail' => implode("\n", $output),
            ], 500);
        }

        // Reject clips that are too short after silence trimming — a sub-0.8 s
        // clip is almost certainly a mis-tap, not a real recording. A VITS model
        // trained on these learns to produce nothing or garbled output.
        if (file_exists($outFile)) {
            $sizeBytes = filesize($outFile);
            // 16 kHz × 2 bytes/sample × 0.8 s = 25 600 bytes (plus 44-byte WAV header)
            $minBytes = 16000 * 2 * 0.8 + 44;
            if ($sizeBytes < $minBytes) {
                unlink($outFile);
                return response()->json([
                    'error' => 'Recording too short. Please speak the full sentence clearly and try again.',
                ], 422);
            }
        }

        $manifest = $this->loadManifest($lang);
        // replace if re-recording same sentence
        $manifest = array_values(array_filter($manifest, fn($r) => $r['id'] !== $id));
        $manifest[] = ['id' => $id, 'text' => $text, 'file' => sprintf('%04d.wav', $id)];
        usort($manifest, fn($a, $b) => $a['id'] - $b['id']);
        $this->saveManifest($lang, $manifest);

        return response()->json(['ok' => true, 'total' => count($manifest)]);
    }

    public function progress(string $lang)
    {
        $lang     = $this->langCode($lang);
        $manifest = $this->loadManifest($lang);

        $scriptPath = $this->scriptPath($lang);
        $total = 0;
        if (file_exists($scriptPath)) {
            $total = count(json_decode(file_get_contents($scriptPath), true));
        }

        return response()->json([
            'recorded' => count($manifest),
            'total'    => $total,
        ]);
    }

    public function export(string $lang)
    {
        $lang     = $this->langCode($lang);
        $manifest = $this->loadManifest($lang);

        if (empty($manifest)) {
            return response()->json(['error' => 'No recordings yet.'], 400);
        }

        $dir     = $this->recordingsDir($lang);
        $zipPath = storage_path("app/voice-studio/" . auth()->id() . "/{$lang}_dataset.zip");

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $csv = "file_name,text\n";
        foreach ($manifest as $row) {
            $wavFile = $dir . $row['file'];
            if (file_exists($wavFile)) {
                $safeText = str_replace(['"', ',', "\n"], ['\\"', ' ', ' '], $row['text']);
                $csv .= "wavs/{$row['file']},\"{$safeText}\"\n";
                $zip->addFile($wavFile, "wavs/{$row['file']}");
            }
        }
        $zip->addFromString('metadata.csv', $csv);
        $zip->close();

        return response()->download($zipPath, "{$lang}_voice_dataset.zip");
    }

    public function destroy(string $lang, int $id)
    {
        $lang     = $this->langCode($lang);
        $manifest = $this->loadManifest($lang);
        $dir      = $this->recordingsDir($lang);

        foreach ($manifest as $row) {
            if ($row['id'] === $id) {
                $wavFile = $dir . $row['file'];
                if (file_exists($wavFile)) {
                    unlink($wavFile);
                }
                break;
            }
        }

        $manifest = array_values(array_filter($manifest, fn($r) => $r['id'] !== $id));
        $this->saveManifest($lang, $manifest);

        return response()->json(['ok' => true]);
    }
}
