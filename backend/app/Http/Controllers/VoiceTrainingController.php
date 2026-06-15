<?php

namespace App\Http\Controllers;

use App\Jobs\StartVoiceTraining;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\VoiceStudioDatasetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class VoiceTrainingController extends Controller
{
    public function __construct(private readonly VoiceStudioDatasetService $datasets) {}

    public function status(): JsonResponse
    {
        PermissionService::require(request()->user(), 'voice_training.view');

        $load = $this->currentLoad();
        $serverFree = $this->serverFree($load);
        $runningJobs = $this->runningJobs();

        return response()->json([
            'checked_at' => now()->toIso8601String(),
            'enabled' => (bool) config('voice_studio.training.enabled'),
            'command_present' => is_file((string) config('voice_studio.training.command')),
            'window' => [
                'start' => (string) config('voice_studio.training.window_start', '02:00'),
                'end' => (string) config('voice_studio.training.window_end', '06:00'),
                'inside' => $this->insideTrainingWindow(),
            ],
            'load' => [
                'current' => $load,
                'max' => (float) config('voice_studio.training.max_load', 2.0),
                'server_free' => $serverFree,
            ],
            'limits' => [
                'min_clips' => (int) config('voice_studio.training.min_clips', 300),
                'min_new_clips' => (int) config('voice_studio.training.min_new_clips', 25),
            ],
            'running_jobs' => $runningJobs,
            'active_models' => $this->activeModels(),
            'datasets' => $this->datasetRows($serverFree, count($runningJobs) > 0),
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        PermissionService::require($request->user(), 'voice_training.view');
        abort_unless($request->user()?->isAdmin(), 403);

        $data = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'lang' => ['nullable', 'string'],
        ]);

        $userId = isset($data['user_id']) ? (int) $data['user_id'] : null;
        $lang = isset($data['lang']) ? $this->normalizeLang((string) $data['lang']) : null;
        if (isset($data['lang']) && $lang === null) {
            return response()->json(['message' => 'Language must be td or my.'], 422);
        }

        if (!config('voice_studio.training.enabled')) {
            return response()->json(['message' => 'Voice training is disabled.'], 422);
        }

        if (!is_file((string) config('voice_studio.training.command'))) {
            return response()->json(['message' => 'Voice training command is not installed.'], 422);
        }

        $load = $this->currentLoad();
        if (!$this->serverFree($load)) {
            return response()->json([
                'message' => 'Server load is above the voice-training threshold.',
                'load' => $load,
                'max_load' => (float) config('voice_studio.training.max_load', 2.0),
            ], 409);
        }

        $runningJobs = $this->runningJobs();
        if ($runningJobs !== []) {
            return response()->json([
                'message' => 'A voice-training job is already running.',
                'running_jobs' => $runningJobs,
            ], 409);
        }

        $rows = $this->datasetRows(true, false);
        $targets = array_values(array_filter($rows, function (array $row) use ($userId, $lang): bool {
            return ($userId === null || (int) $row['user_id'] === $userId)
                && ($lang === null || (string) $row['lang'] === $lang);
        }));
        $ready = array_values(array_filter($targets, fn(array $row) => (bool) $row['can_start']));

        if ($ready === []) {
            $reason = $targets[0]['reason'] ?? 'No voice datasets are ready for training.';
            return response()->json(['message' => $reason, 'datasets' => $targets], 422);
        }

        StartVoiceTraining::dispatch($userId, $lang);

        return response()->json([
            'ok' => true,
            'message' => 'Voice training queued.',
            'target' => [
                'user_id' => $userId,
                'lang' => $lang,
            ],
        ]);
    }

    private function datasetRows(bool $serverFree, bool $anyRunning): array
    {
        $items = $this->datasets->allDatasets();
        $users = User::whereIn('id', collect($items)->pluck('user_id')->unique())
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $rows = array_map(function (array $dataset) use ($users, $serverFree, $anyRunning): array {
            $userId = (int) $dataset['user_id'];
            $lang = (string) $dataset['lang'];
            $user = $users->get($userId);

            return $this->datasetRow($userId, $lang, $user, $serverFree, $anyRunning);
        }, $items);

        usort($rows, fn(array $a, array $b) => [$a['user_id'], $a['lang']] <=> [$b['user_id'], $b['lang']]);

        return $rows;
    }

    private function datasetRow(int $userId, string $lang, ?User $user, bool $serverFree, bool $anyRunning): array
    {
        $meta = VoiceStudioDatasetService::LANGUAGES[$lang] ?? [
            'label' => $lang,
            'speech_lang' => $lang,
            'mms_code' => $lang,
        ];
        $statusError = null;
        $status = ['status' => 'unknown'];
        try {
            $status = $this->datasets->loadTrainingStatus($userId, $lang);
        } catch (Throwable $exc) {
            $statusError = $exc->getMessage();
        }

        $statusValue = (string) ($status['status'] ?? 'unknown');
        $running = $statusValue === 'running' && $this->pidIsAlive($status['pid'] ?? null);
        if ($statusValue === 'running' && !$running) {
            $statusValue = 'stale';
        }

        $snapshot = null;
        $syncError = null;
        try {
            $snapshot = $this->datasets->syncSnapshot($userId, $lang);
            $recordings = (int) $snapshot['recordings'];
        } catch (Throwable $exc) {
            $syncError = $exc->getMessage();
            $recordings = $this->manifestCount($userId, $lang);
        }

        [$canStart, $reason] = $this->readinessReason(
            $snapshot,
            $status,
            $recordings,
            $syncError,
            $running,
            $serverFree,
            $anyRunning
        );

        $lastSuccessRecordings = (int) ($status['last_success_recordings'] ?? 0);

        return [
            'user_id' => $userId,
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'lang' => $lang,
            'label' => $meta['label'],
            'mms_code' => $meta['mms_code'],
            'recordings' => $recordings,
            'dataset_hash' => $snapshot['dataset_hash'] ?? null,
            'dataset_dir' => $snapshot['dataset_dir'] ?? $this->datasets->datasetDir($userId, $lang),
            'synced_at' => $snapshot['synced_at'] ?? null,
            'status' => $statusValue,
            'running' => $running,
            'pid' => $running ? (int) $status['pid'] : null,
            'started_at' => $status['started_at'] ?? null,
            'finished_at' => $status['finished_at'] ?? null,
            'last_success_at' => $status['last_success_at'] ?? null,
            'last_success_recordings' => $lastSuccessRecordings,
            'new_recordings_since_success' => max(0, $recordings - $lastSuccessRecordings),
            'model_dir' => $status['model_dir'] ?? null,
            'log_file' => $status['log_file'] ?? null,
            'last_error' => $status['last_error'] ?? $statusError,
            'can_start' => $canStart,
            'reason' => $reason,
        ];
    }

    private function readinessReason(
        ?array $snapshot,
        array $status,
        int $recordings,
        ?string $syncError,
        bool $running,
        bool $serverFree,
        bool $anyRunning
    ): array {
        $minClips = (int) config('voice_studio.training.min_clips', 300);
        $minNewClips = (int) config('voice_studio.training.min_new_clips', 25);

        if (!config('voice_studio.training.enabled')) {
            return [false, 'Voice training is disabled.'];
        }

        if (!is_file((string) config('voice_studio.training.command'))) {
            return [false, 'Voice training command is not installed.'];
        }

        if (!$serverFree) {
            return [false, 'Server load is above the voice-training threshold.'];
        }

        if ($running) {
            return [false, 'Training is already running.'];
        }

        if ($anyRunning) {
            return [false, 'Another voice-training job is already running.'];
        }

        if ($syncError !== null) {
            return [false, 'Dataset sync failed: ' . $syncError];
        }

        if ($recordings < $minClips) {
            return [false, "Needs {$minClips} clips; has {$recordings}."];
        }

        if ($snapshot !== null && ($status['last_success_hash'] ?? null) === $snapshot['dataset_hash']) {
            return [false, 'Already trained this dataset snapshot.'];
        }

        $lastSuccessRecordings = (int) ($status['last_success_recordings'] ?? 0);
        if ($lastSuccessRecordings > 0 && ($recordings - $lastSuccessRecordings) < $minNewClips) {
            return [false, "Needs {$minNewClips} new clips since the last successful training."];
        }

        return [true, 'Ready to train.'];
    }

    private function runningJobs(): array
    {
        $running = [];
        foreach ($this->datasets->allDatasets() as $dataset) {
            try {
                $status = $this->datasets->loadTrainingStatus((int) $dataset['user_id'], (string) $dataset['lang']);
            } catch (Throwable) {
                continue;
            }

            if (($status['status'] ?? null) !== 'running' || !$this->pidIsAlive($status['pid'] ?? null)) {
                continue;
            }

            $running[] = [
                'user_id' => (int) $dataset['user_id'],
                'lang' => (string) $dataset['lang'],
                'pid' => (int) $status['pid'],
                'started_at' => $status['started_at'] ?? null,
                'recordings' => $status['recordings'] ?? null,
                'log_file' => $status['log_file'] ?? null,
            ];
        }

        return $running;
    }

    private function activeModels(): array
    {
        $path = storage_path('app/voice-studio/active_models.json');
        $decoded = [];
        if (file_exists($path)) {
            $json = json_decode(file_get_contents($path), true);
            $decoded = is_array($json) ? $json : [];
        }

        $models = [];
        foreach (VoiceStudioDatasetService::LANGUAGES as $lang => $meta) {
            $modelPath = is_string($decoded[$lang] ?? null) ? $decoded[$lang] : null;
            $models[$lang] = [
                'label' => $meta['label'],
                'path' => $modelPath,
                'exists' => $modelPath !== null && is_dir($modelPath),
            ];
        }

        return $models;
    }

    private function manifestCount(int $userId, string $lang): int
    {
        try {
            return count(array_filter($this->datasets->loadManifest($userId, $lang), function (array $row): bool {
                return (string) ($row['file'] ?? '') !== '' && (string) ($row['text'] ?? '') !== '';
            }));
        } catch (Throwable) {
            return 0;
        }
    }

    private function normalizeLang(string $lang): ?string
    {
        return match ($lang) {
            'td', 'tedim' => 'td',
            'my', 'burmese' => 'my',
            default => null,
        };
    }

    private function currentLoad(): ?float
    {
        $load = sys_getloadavg();
        if (!is_array($load) || !isset($load[0])) {
            return null;
        }

        return round((float) $load[0], 2);
    }

    private function serverFree(?float $load): bool
    {
        return $load === null || $load <= (float) config('voice_studio.training.max_load', 2.0);
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

    private function pidIsAlive(mixed $pid): bool
    {
        if (!$pid || !ctype_digit((string) $pid)) {
            return false;
        }

        return file_exists('/proc/' . (int) $pid);
    }
}
