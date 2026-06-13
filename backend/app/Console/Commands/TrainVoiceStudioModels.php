<?php

namespace App\Console\Commands;

use App\Services\VoiceStudioDatasetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TrainVoiceStudioModels extends Command
{
    protected $signature = 'voice-studio:train-due
        {--force : Ignore the time/load/new-recording gates}
        {--ignore-window : Ignore only the configured training window}
        {--user_id= : Train only datasets owned by this user}
        {--lang= : Train only this language (td/my)}
        {--dry-run : Show what would train without starting it}';
    protected $description = 'Fine-tune due Voice Studio MMS/VITS models during the low-load window';

    public function __construct(private readonly VoiceStudioDatasetService $datasets)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $ignoreWindow = (bool) $this->option('ignore-window');
        $dryRun = (bool) $this->option('dry-run');
        $userFilter = $this->userFilter();
        $langFilter = $this->langFilter();

        if ($userFilter === false || $langFilter === false) {
            return self::FAILURE;
        }

        if (!config('voice_studio.training.enabled') && !$force) {
            $this->info('Voice training is disabled.');
            return self::SUCCESS;
        }

        if (!$force && !$ignoreWindow && !$this->insideTrainingWindow()) {
            $this->info('Outside voice-training window.');
            return self::SUCCESS;
        }

        if (!$force && !$this->serverLoadIsLow()) {
            $this->info('Server load is above the voice-training threshold.');
            return self::SUCCESS;
        }

        $command = (string) config('voice_studio.training.command');
        if (!is_file($command)) {
            $this->error("Voice training command not found: {$command}");
            return self::FAILURE;
        }

        foreach ($this->filteredDatasets($userFilter, $langFilter) as $dataset) {
            try {
                $candidate = $this->candidate($dataset['user_id'], $dataset['lang'], $force);
            } catch (\Throwable $exc) {
                $this->line("skip user={$dataset['user_id']} lang={$dataset['lang']}: " . $exc->getMessage());
                Log::warning('Voice Studio dataset skipped before training', [
                    'user_id' => $dataset['user_id'],
                    'lang' => $dataset['lang'],
                    'error' => $exc->getMessage(),
                ]);
                continue;
            }
            if (!$candidate['due']) {
                $this->line("skip user={$dataset['user_id']} lang={$dataset['lang']}: {$candidate['reason']}");
                continue;
            }

            if ($dryRun) {
                $this->info("would train user={$dataset['user_id']} lang={$dataset['lang']} recordings={$candidate['snapshot']['recordings']}");
                return self::SUCCESS;
            }

            return $this->runTraining($dataset['user_id'], $dataset['lang'], $candidate['snapshot'], $command);
        }

        $this->info('No voice datasets are due for training.');
        return self::SUCCESS;
    }

    private function userFilter(): int|false|null
    {
        $value = $this->option('user_id');
        if ($value === null || $value === '') {
            return null;
        }

        if (!ctype_digit((string) $value)) {
            $this->error('The --user_id option must be an integer.');
            return false;
        }

        return (int) $value;
    }

    private function langFilter(): string|false|null
    {
        $value = $this->option('lang');
        if ($value === null || $value === '') {
            return null;
        }

        $lang = match ((string) $value) {
            'td', 'tedim' => 'td',
            'my', 'burmese' => 'my',
            default => null,
        };

        if ($lang === null) {
            $this->error('The --lang option must be td or my.');
            return false;
        }

        return $lang;
    }

    private function filteredDatasets(?int $userFilter, ?string $langFilter): array
    {
        return array_values(array_filter(
            $this->datasets->allDatasets(),
            fn(array $dataset) =>
                ($userFilter === null || (int) $dataset['user_id'] === $userFilter)
                && ($langFilter === null || (string) $dataset['lang'] === $langFilter)
        ));
    }

    private function insideTrainingWindow(): bool
    {
        $start = (string) config('voice_studio.training.window_start', '02:00');
        $end = (string) config('voice_studio.training.window_end', '06:00');
        $now = now()->format('H:i');

        if ($start <= $end) {
            return $now >= $start && $now < $end;
        }

        return $now >= $start || $now < $end;
    }

    private function serverLoadIsLow(): bool
    {
        $load = sys_getloadavg();
        if (!is_array($load) || !isset($load[0])) {
            return true;
        }

        return $load[0] <= (float) config('voice_studio.training.max_load', 2.0);
    }

    private function candidate(int $userId, string $lang, bool $force): array
    {
        $snapshot = $this->datasets->syncSnapshot($userId, $lang);
        $status = $this->datasets->loadTrainingStatus($userId, $lang);
        $recordings = (int) $snapshot['recordings'];
        $minClips = (int) config('voice_studio.training.min_clips', 300);
        $minNewClips = (int) config('voice_studio.training.min_new_clips', 25);

        if (($status['status'] ?? null) === 'running' && $this->pidIsAlive($status['pid'] ?? null)) {
            return ['due' => false, 'reason' => 'already running', 'snapshot' => $snapshot];
        }

        if (!$force && $recordings < $minClips) {
            return ['due' => false, 'reason' => "needs {$minClips} clips; has {$recordings}", 'snapshot' => $snapshot];
        }

        if (!$force && ($status['last_success_hash'] ?? null) === $snapshot['dataset_hash']) {
            return ['due' => false, 'reason' => 'already trained this dataset hash', 'snapshot' => $snapshot];
        }

        $lastSuccessRecordings = (int) ($status['last_success_recordings'] ?? 0);
        if (!$force && $lastSuccessRecordings > 0 && ($recordings - $lastSuccessRecordings) < $minNewClips) {
            return [
                'due' => false,
                'reason' => "needs {$minNewClips} new clips since last success",
                'snapshot' => $snapshot,
            ];
        }

        return ['due' => true, 'reason' => 'due', 'snapshot' => $snapshot];
    }

    private function runTraining(int $userId, string $lang, array $snapshot, string $command): int
    {
        $meta = VoiceStudioDatasetService::LANGUAGES[$lang];
        $stamp = now()->format('Ymd_His');
        $hashShort = substr($snapshot['dataset_hash'], 0, 8);
        $root = $this->datasets->languageDir($userId, $lang);
        $outputDir = "{$root}/models/{$stamp}_{$hashShort}";
        $logDir = "{$root}/logs";
        $logFile = "{$logDir}/train_{$stamp}_{$hashShort}.log";

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0775, true);
        }
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $runningStatus = [
            'status' => 'running',
            'pid' => getmypid(),
            'user_id' => $userId,
            'lang' => $lang,
            'recordings' => $snapshot['recordings'],
            'dataset_hash' => $snapshot['dataset_hash'],
            'dataset_dir' => $snapshot['dataset_dir'],
            'model_dir' => $outputDir,
            'log_file' => $logFile,
            'started_at' => now()->toIso8601String(),
        ];
        $this->datasets->saveTrainingStatus($userId, $lang, $runningStatus);

        $env = [
            'DATASET_DIR' => $snapshot['dataset_dir'],
            'DATASET_SCRIPT' => $snapshot['dataset_script'],
            'OUTPUT_DIR' => $outputDir,
            'LANGUAGE_CODE' => $meta['mms_code'],
            'VOICE_TRAIN_EPOCHS' => (string) config('voice_studio.training.epochs', 120),
            'VOICE_TRAIN_BATCH_SIZE' => (string) config('voice_studio.training.batch_size', 8),
            'VOICE_TRAIN_LEARNING_RATE' => (string) config('voice_studio.training.learning_rate', '2e-5'),
        ];

        $envPrefix = 'env ' . collect($env)
            ->map(fn(string $value, string $key) => $key . '=' . escapeshellarg($value))
            ->implode(' ');
        $shell = "{$envPrefix} bash " . escapeshellarg($command) . ' >> ' . escapeshellarg($logFile) . ' 2>&1';

        $this->info("training user={$userId} lang={$lang} recordings={$snapshot['recordings']}");
        file_put_contents($logFile, '[' . now()->toIso8601String() . "] starting {$shell}\n", FILE_APPEND);
        exec($shell, $output, $code);

        $status = $runningStatus + [
            'finished_at' => now()->toIso8601String(),
        ];

        if ($code === 0) {
            $this->activateModel($lang, $outputDir);
            $status['status'] = 'succeeded';
            $status['last_success_at'] = now()->toIso8601String();
            $status['last_success_hash'] = $snapshot['dataset_hash'];
            $status['last_success_recordings'] = $snapshot['recordings'];
            unset($status['pid']);
            $this->datasets->saveTrainingStatus($userId, $lang, $status);
            $this->info("training succeeded: {$outputDir}");
            return self::SUCCESS;
        }

        $status['status'] = 'failed';
        $status['last_error'] = "training command exited {$code}";
        unset($status['pid']);
        $this->datasets->saveTrainingStatus($userId, $lang, $status);
        Log::warning('Voice Studio training failed', $status);
        $this->error("training failed, see {$logFile}");

        return self::FAILURE;
    }

    private function activateModel(string $lang, string $modelDir): void
    {
        $activePath = storage_path('app/voice-studio/active_models.json');
        $activeDir = dirname($activePath);
        if (!is_dir($activeDir)) {
            mkdir($activeDir, 0775, true);
        }
        @chmod($activeDir, 0775);

        $models = [];
        if (file_exists($activePath)) {
            $decoded = json_decode(file_get_contents($activePath), true);
            $models = is_array($decoded) ? $decoded : [];
        }
        $models[$lang] = $modelDir;

        file_put_contents(
            $activePath,
            json_encode($models, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );

        try {
            Http::timeout(5)->post(rtrim((string) config('services.mms_speech.url'), '/') . '/tts/reload');
        } catch (\Throwable $exc) {
            Log::warning('Could not reload MMS TTS after voice training', [
                'lang' => $lang,
                'model_dir' => $modelDir,
                'error' => $exc->getMessage(),
            ]);
        }
    }

    private function pidIsAlive(mixed $pid): bool
    {
        if (!$pid || !ctype_digit((string) $pid)) {
            return false;
        }

        return file_exists('/proc/' . (int) $pid);
    }
}
