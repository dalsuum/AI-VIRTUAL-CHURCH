<?php

namespace App\Http\Controllers;

use App\Services\VoiceStudioDatasetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VoiceStudioController extends Controller
{
    public function __construct(private readonly VoiceStudioDatasetService $datasets) {}

    private function langCode(string $lang): string
    {
        return $this->datasets->langCode($lang);
    }

    private function scriptPath(string $lang): string
    {
        return resource_path("voice-studio/{$lang}_script.json");
    }

    private function recordingsDir(string $lang): string
    {
        return $this->datasets->languageDir(auth()->id(), $lang) . '/';
    }

    private function manifestPath(string $lang): string
    {
        return $this->datasets->manifestPath(auth()->id(), $lang);
    }

    private function speechUrl(): string
    {
        return rtrim((string) config('services.mms_speech.url', 'http://127.0.0.1:8003'), '/');
    }

    private function scriptSentences(string $lang): array
    {
        $path = $this->scriptPath($lang);
        if (!file_exists($path)) {
            return [];
        }

        $sentences = json_decode(file_get_contents($path), true);
        return is_array($sentences) ? $sentences : [];
    }

    private function sentenceFor(string $lang, int $id): ?array
    {
        foreach ($this->scriptSentences($lang) as $sentence) {
            if ((int) ($sentence['id'] ?? 0) === $id) {
                return $sentence;
            }
        }

        return null;
    }

    private function loadManifest(string $lang): array
    {
        return $this->datasets->loadManifest(auth()->id(), $lang);
    }

    private function saveManifest(string $lang, array $manifest): void
    {
        $this->datasets->saveManifest(auth()->id(), $lang, $manifest);
    }

    private function ffmpegAvailable(): bool
    {
        exec('command -v ffmpeg 2>/dev/null', $out, $code);
        return $code === 0 && !empty($out);
    }

    public function script(string $lang)
    {
        $lang = $this->langCode($lang);
        $path = $this->scriptPath($lang);

        if (!file_exists($path)) {
            return response()->json(['error' => 'Script not found. Contact admin.'], 404);
        }

        $sentences = $this->scriptSentences($lang);
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
            'text'  => 'nullable|string|max:2000',
            'audio' => 'required|file|max:10240',
        ]);

        $lang  = $req->input('lang');
        $id    = (int) $req->input('id');
        $audio = $req->file('audio');

        $sentence = $this->sentenceFor($lang, $id);
        if (!$sentence) {
            return response()->json(['error' => 'Sentence not found in the recording script.'], 422);
        }
        $text = (string) $sentence['text'];

        if (!$this->ffmpegAvailable()) {
            return response()->json(['error' => 'ffmpeg is not installed on the backend server.'], 503);
        }

        $dir = $this->recordingsDir($lang);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        @chmod($dir, 0775);

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
            Log::error('Audio conversion failed', ['output' => $output]);
            return response()->json(['error' => 'Audio conversion failed'], 500);
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
        $manifest[] = [
            'id' => $id,
            'text' => $text,
            'file' => sprintf('%04d.wav', $id),
            'recorded_at' => now()->toIso8601String(),
        ];
        usort($manifest, fn($a, $b) => $a['id'] - $b['id']);
        $this->saveManifest($lang, $manifest);
        $snapshot = $this->datasets->syncSnapshot(auth()->id(), $lang);

        return response()->json([
            'ok' => true,
            'total' => count($manifest),
            'dataset' => [
                'recordings' => $snapshot['recordings'],
                'dataset_hash' => $snapshot['dataset_hash'],
                'synced_at' => $snapshot['synced_at'],
            ],
        ]);
    }

    public function transcribe(Request $req)
    {
        $req->validate([
            'lang'  => 'required|in:td,my',
            'audio' => 'required|file|max:10240',
        ]);

        $lang = $req->input('lang');
        $audio = $req->file('audio');
        $handle = fopen($audio->getRealPath(), 'r');

        try {
            $res = Http::timeout((int) config('services.mms_speech.asr_timeout', 300))
                ->attach('audio', $handle, $audio->getClientOriginalName() ?: 'recording.webm')
                ->post($this->speechUrl() . '/stt/transcribe', [
                    'lang' => VoiceStudioDatasetService::LANGUAGES[$lang]['speech_lang'],
                ]);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if (!$res->successful()) {
            Log::warning('Voice Studio transcription failed', [
                'status' => $res->status(),
                'body' => $res->body(),
            ]);

            return response()->json([
                'error' => $res->json('detail') ?: 'Transcription service failed.',
            ], $res->status() >= 400 ? $res->status() : 502);
        }

        return response()->json([
            'text' => $res->json('text', ''),
            'lang' => $lang,
        ]);
    }

    public function progress(string $lang)
    {
        $lang     = $this->langCode($lang);
        $manifest = $this->loadManifest($lang);

        $total = count($this->scriptSentences($lang));

        return response()->json([
            'recorded' => count($manifest),
            'total'    => $total,
        ]);
    }

    public function status()
    {
        $speech = [
            'url' => $this->speechUrl(),
            'reachable' => false,
            'error' => null,
        ];

        try {
            $res = Http::timeout(2)->get($this->speechUrl() . '/health');
            $speech['reachable'] = $res->successful();
            if (!$res->successful()) {
                $speech['error'] = 'HTTP ' . $res->status();
            }
        } catch (\Throwable $exc) {
            $speech['error'] = $exc->getMessage();
        }

        $languages = [];
        foreach (VoiceStudioDatasetService::LANGUAGES as $code => $meta) {
            $training = $this->datasets->loadTrainingStatus(auth()->id(), $code);
            $languages[] = [
                'code' => $code,
                'label' => $meta['label'],
                'script_total' => count($this->scriptSentences($code)),
                'recorded' => count($this->loadManifest($code)),
                'tts_model' => $code === 'td'
                    ? env('MMS_TTS_MODEL_TD', 'facebook/mms-tts-ctd')
                    : env('MMS_TTS_MODEL_MY', 'facebook/mms-tts-mya'),
                'stt_lang' => $meta['mms_code'],
                'training' => [
                    'status' => $training['status'] ?? 'never',
                    'recordings' => $training['recordings'] ?? null,
                    'last_success_at' => $training['last_success_at'] ?? null,
                    'last_error' => $training['last_error'] ?? null,
                    'model_dir' => $training['model_dir'] ?? null,
                ],
            ];
        }

        return response()->json([
            'training' => [
                'in_app' => true,
                'mode' => 'scheduled_server',
                'dataset_export' => true,
                'automatic_fine_tune' => (bool) config('voice_studio.training.enabled'),
                'window_start' => config('voice_studio.training.window_start'),
                'window_end' => config('voice_studio.training.window_end'),
                'max_load' => config('voice_studio.training.max_load'),
                'min_clips' => config('voice_studio.training.min_clips'),
                'min_new_clips' => config('voice_studio.training.min_new_clips'),
            ],
            'tools' => [
                'ffmpeg' => $this->ffmpegAvailable(),
                'zip' => class_exists(\ZipArchive::class),
            ],
            'speech' => $speech,
            'languages' => $languages,
        ]);
    }

    public function export(string $lang)
    {
        $lang     = $this->langCode($lang);
        $manifest = $this->loadManifest($lang);

        if (empty($manifest)) {
            return response()->json(['error' => 'No recordings yet.'], 400);
        }

        $snapshot = $this->datasets->syncSnapshot(auth()->id(), $lang, writeZip: true);
        if (($snapshot['recordings'] ?? 0) === 0) {
            return response()->json(['error' => 'No WAV files found for export.'], 400);
        }

        return response()->download($snapshot['zip_path'], "{$lang}_voice_dataset.zip");
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
        $this->datasets->syncSnapshot(auth()->id(), $lang);

        return response()->json(['ok' => true]);
    }
}
