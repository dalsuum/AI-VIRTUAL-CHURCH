<?php

namespace App\Services;

use ZipArchive;

class VoiceStudioDatasetService
{
    private const STORAGE_GROUP = 'www-data';

    public const LANGUAGES = [
        'td' => ['label' => 'Tedim (Zolai)', 'speech_lang' => 'tedim', 'mms_code' => 'ctd'],
        'my' => ['label' => 'Myanmar (Burmese)', 'speech_lang' => 'burmese', 'mms_code' => 'mya'],
    ];

    public function langCode(string $lang): string
    {
        return match ($lang) {
            'tedim', 'td' => 'td',
            'burmese', 'my' => 'my',
            default => abort(404, 'Unknown language'),
        };
    }

    public function userRoot(int $userId): string
    {
        return storage_path("app/voice-studio/{$userId}");
    }

    public function languageDir(int $userId, string $lang): string
    {
        return $this->userRoot($userId) . '/' . $this->langCode($lang);
    }

    public function manifestPath(int $userId, string $lang): string
    {
        return $this->languageDir($userId, $lang) . '/manifest.json';
    }

    public function datasetDir(int $userId, string $lang): string
    {
        return $this->languageDir($userId, $lang) . '/dataset';
    }

    public function datasetZipPath(int $userId, string $lang): string
    {
        return $this->languageDir($userId, $lang) . '/dataset.zip';
    }

    public function trainingStatusPath(int $userId, string $lang): string
    {
        return $this->languageDir($userId, $lang) . '/training_status.json';
    }

    public function loadManifest(int $userId, string $lang): array
    {
        $path = $this->manifestPath($userId, $lang);
        if (!file_exists($path)) {
            return [];
        }

        $manifest = json_decode(file_get_contents($path), true);
        return is_array($manifest) ? $manifest : [];
    }

    public function saveManifest(int $userId, string $lang, array $manifest): void
    {
        $this->ensureDir($this->userRoot($userId));
        $dir = $this->languageDir($userId, $lang);
        $this->ensureDir($dir);

        $this->writeSharedFile(
            $this->manifestPath($userId, $lang),
            json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    public function loadTrainingStatus(int $userId, string $lang): array
    {
        $path = $this->trainingStatusPath($userId, $lang);
        if (!file_exists($path)) {
            return ['status' => 'never'];
        }

        $status = json_decode(file_get_contents($path), true);
        return is_array($status) ? $status : ['status' => 'unknown'];
    }

    public function saveTrainingStatus(int $userId, string $lang, array $status): void
    {
        $dir = $this->languageDir($userId, $lang);
        $this->ensureDir($dir);

        $this->writeSharedFile(
            $this->trainingStatusPath($userId, $lang),
            json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    public function syncSnapshot(int $userId, string $lang, bool $writeZip = false): array
    {
        $lang = $this->langCode($lang);
        $manifest = $this->loadManifest($userId, $lang);
        $this->ensureDir($this->userRoot($userId));
        $sourceDir = $this->languageDir($userId, $lang);
        $datasetDir = $this->datasetDir($userId, $lang);
        $wavsDir = "{$datasetDir}/wavs";

        $this->ensureDir($sourceDir);
        $this->ensureDir($datasetDir);
        $this->ensureDir($wavsDir);

        $rows = [];
        $hashParts = [];
        foreach ($manifest as $row) {
            $file = (string) ($row['file'] ?? '');
            $text = (string) ($row['text'] ?? '');
            if ($file === '' || $text === '') {
                continue;
            }

            $source = "{$sourceDir}/{$file}";
            if (!file_exists($source)) {
                continue;
            }

            $target = "{$wavsDir}/{$file}";
            if (!file_exists($target) || filesize($target) !== filesize($source) || filemtime($target) < filemtime($source)) {
                copy($source, $target);
                $this->ensureFile($target);
            }

            $rows[] = ['file_name' => "wavs/{$file}", 'text' => $text];
            $hashParts[] = implode('|', [
                (int) ($row['id'] ?? 0),
                $file,
                filesize($source),
                filemtime($source),
                hash('sha256', $text),
            ]);
        }

        usort($rows, fn(array $a, array $b) => strcmp($a['file_name'], $b['file_name']));

        $metadataPath = "{$datasetDir}/metadata.csv";
        $csv = fopen($metadataPath, 'w');
        fputcsv($csv, ['file_name', 'text']);
        foreach ($rows as $row) {
            fputcsv($csv, [$row['file_name'], $row['text']]);
        }
        fclose($csv);
        $this->ensureFile($metadataPath);
        $datasetScript = "{$datasetDir}/voice_studio_dataset.py";
        $this->writeSharedFile($datasetScript, $this->datasetLoaderScript());

        sort($hashParts);
        $hash = hash('sha256', implode("\n", $hashParts));

        $state = [
            'user_id' => $userId,
            'lang' => $lang,
            'recordings' => count($rows),
            'dataset_dir' => $datasetDir,
            'dataset_script' => $datasetScript,
            'metadata_path' => $metadataPath,
            'dataset_hash' => $hash,
            'synced_at' => now()->toIso8601String(),
        ];

        $this->writeSharedFile(
            "{$datasetDir}/dataset_state.json",
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        if ($writeZip) {
            $state['zip_path'] = $this->writeZip($datasetDir, $this->datasetZipPath($userId, $lang));
        }

        return $state;
    }

    private function datasetLoaderScript(): string
    {
        return <<<'PY'
import csv
import os

import datasets


class VoiceStudioDataset(datasets.GeneratorBasedBuilder):
    VERSION = datasets.Version("1.0.0")

    def _info(self):
        return datasets.DatasetInfo(
            features=datasets.Features(
                {
                    "audio": datasets.Audio(sampling_rate=16000),
                    "text": datasets.Value("string"),
                }
            )
        )

    def _split_generators(self, dl_manager):
        base_dir = os.path.dirname(os.path.abspath(__file__))
        return [
            datasets.SplitGenerator(
                name=datasets.Split.TRAIN,
                gen_kwargs={"base_dir": base_dir, "metadata_path": os.path.join(base_dir, "metadata.csv")},
            )
        ]

    def _generate_examples(self, base_dir, metadata_path):
        with open(metadata_path, newline="", encoding="utf-8") as handle:
            reader = csv.DictReader(handle)
            for idx, row in enumerate(reader):
                audio_path = os.path.join(base_dir, row["file_name"])
                if os.path.exists(audio_path):
                    yield idx, {"audio": audio_path, "text": row["text"]}
PY;
    }

    public function writeZip(string $datasetDir, string $zipPath): string
    {
        if (!class_exists(ZipArchive::class)) {
            abort(503, 'PHP zip extension is not installed.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            abort(500, 'Could not create dataset zip.');
        }

        $metadataPath = "{$datasetDir}/metadata.csv";
        if (file_exists($metadataPath)) {
            $zip->addFile($metadataPath, 'metadata.csv');
        }

        $datasetScript = "{$datasetDir}/voice_studio_dataset.py";
        if (file_exists($datasetScript)) {
            $zip->addFile($datasetScript, 'voice_studio_dataset.py');
        }

        foreach (glob("{$datasetDir}/wavs/*.wav") ?: [] as $wavFile) {
            $zip->addFile($wavFile, 'wavs/' . basename($wavFile));
        }

        $zip->close();
        $this->ensureFile($zipPath);

        return $zipPath;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        @chgrp($dir, self::STORAGE_GROUP);
        @chmod($dir, 02775);
    }

    private function writeSharedFile(string $path, string $contents): void
    {
        file_put_contents($path, $contents, LOCK_EX);
        $this->ensureFile($path);
    }

    private function ensureFile(string $path): void
    {
        @chgrp($path, self::STORAGE_GROUP);
        @chmod($path, 0664);
    }

    public function allDatasets(): array
    {
        $items = [];
        foreach (glob(storage_path('app/voice-studio/*'), GLOB_ONLYDIR) ?: [] as $userDir) {
            $userId = basename($userDir);
            if (!ctype_digit($userId)) {
                continue;
            }

            foreach (array_keys(self::LANGUAGES) as $lang) {
                if (file_exists($this->manifestPath((int) $userId, $lang))) {
                    $items[] = ['user_id' => (int) $userId, 'lang' => $lang];
                }
            }
        }

        return $items;
    }
}
