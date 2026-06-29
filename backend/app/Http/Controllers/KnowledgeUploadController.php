<?php

namespace App\Http\Controllers;

use App\Jobs\KnowledgeIngestJob;
use App\Models\KnowledgeIngestJob as IngestRecord;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;

/**
 * Knowledge Operations Platform — file upload and job management.
 *
 * upload()  POST  /admin/knowledge/upload   multipart; stores temp file, detects
 *                                           duplicates, dispatches KnowledgeIngestJob
 * jobs()    GET   /admin/knowledge/jobs     list recent jobs for the dashboard
 * cancel()  POST  /admin/knowledge/jobs/{id}/cancel   mark pending/processing as cancelled
 * retry()   POST  /admin/knowledge/jobs/{id}/retry    re-queue a failed job
 * destroy() DELETE /admin/knowledge/jobs/{id}         delete a terminal job record
 */
final class KnowledgeUploadController extends Controller
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'json', 'md', 'txt'];
    private const MAX_FILE_MB = 50;

    public function upload(Request $request): JsonResponse
    {
        PermissionService::require($request->user(), 'knowledge.view');

        $request->validate([
            'file'       => ['required', File::types(self::ALLOWED_EXTENSIONS)->max(self::MAX_FILE_MB * 1024)],
            'collection' => ['required', 'string', 'max:64', 'in:' . implode(',', (array) config('knowledge.corpora', []))],
            'language'   => ['required', 'string', 'max:8'],
            'source'     => ['nullable', 'string', 'max:128'],
            'chunker'    => ['nullable', 'string', 'in:text,bible'],
        ]);

        $file       = $request->file('file');
        $collection = $request->input('collection');
        $language   = $request->input('language', 'en');
        $source     = $request->input('source') ?: $collection;
        $chunker    = $request->input('chunker', 'text');

        // ── Pre-queue lightweight validation ─────────────────────────────────
        $ext = strtolower($file->getClientOriginalExtension());
        if ($file->getSize() === 0) {
            return response()->json(['error' => 'validation', 'message' => 'File is empty.'], 422);
        }
        $rawContent = file_get_contents($file->getRealPath(), false, null, 0, 512) ?: '';
        if ($ext === 'pdf' && !str_starts_with($rawContent, '%PDF')) {
            return response()->json(['error' => 'validation', 'message' => 'File does not appear to be a valid PDF (missing %PDF header).'], 422);
        }
        if ($ext === 'json') {
            $probe = file_get_contents($file->getRealPath());
            if (json_decode($probe) === null && json_last_error() !== JSON_ERROR_NONE) {
                return response()->json(['error' => 'validation', 'message' => 'File is not valid JSON: ' . json_last_error_msg()], 422);
            }
        }
        if (in_array($ext, ['md', 'txt'], true) && trim($rawContent) === '') {
            return response()->json(['error' => 'validation', 'message' => 'File appears to be blank.'], 422);
        }

        // ── Idempotency key: corpus + content + ingestion parameters ──────────
        // Changing the chunker, language, or embedding driver creates a new key
        // so re-ingesting with different parameters is treated as a new run.
        $hash = hash_file('sha256', $file->getRealPath());
        $idempotencyKey = hash('sha256', implode('|', [
            $collection,
            $hash,
            $chunker,
            $language,
            config('knowledge.embedding.driver', 'hash'),
            config('knowledge.embedding.dimensions', 384),
        ]));

        $duplicate = IngestRecord::where('idempotency_key', $idempotencyKey)
            ->where('status', 'completed')
            ->first();

        if ($duplicate) {
            return response()->json([
                'error'        => 'duplicate',
                'message'      => "This file was already ingested into '{$collection}' with the same parameters (job #{$duplicate->id}).",
                'existing_job' => $this->formatJob($duplicate),
            ], 409);
        }

        // Store temp file under storage/app/knowledge/uploads/{hash} to avoid leaking
        // the original filename in the path and to deduplicate concurrent uploads.
        $storagePath = "knowledge/uploads/{$hash}.{$ext}";
        Storage::put($storagePath, file_get_contents($file->getRealPath()));

        $record = IngestRecord::create([
            'created_by'        => $request->user()->id,
            'collection'        => $collection,
            'original_filename' => $file->getClientOriginalName(),
            'file_hash'         => $hash,
            'idempotency_key'   => $idempotencyKey,
            'file_size'         => $file->getSize(),
            'status'            => 'pending',
            'metadata'          => ['language' => $language, 'source' => $source, 'chunker' => $chunker],
            'storage_path'      => $storagePath,
        ]);

        KnowledgeIngestJob::dispatch($record->id);

        Log::info('knowledge.upload', [
            'job_id'     => $record->id,
            'collection' => $collection,
            'filename'   => $file->getClientOriginalName(),
            'size'       => $file->getSize(),
            'user'       => $request->user()->id,
        ]);

        return response()->json(['job' => $this->formatJob($record)], 201);
    }

    public function jobs(Request $request): JsonResponse
    {
        PermissionService::require($request->user(), 'knowledge.view');

        $jobs = IngestRecord::orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($j) => $this->formatJob($j));

        return response()->json(['jobs' => $jobs]);
    }

    public function cancel(Request $request, IngestRecord $job): JsonResponse
    {
        PermissionService::require($request->user(), 'knowledge.view');

        if (! in_array($job->status, ['pending', 'processing'], true)) {
            return response()->json(['error' => 'Job is already in a terminal state.'], 422);
        }

        $job->update(['status' => 'cancelled']);

        return response()->json(['job' => $this->formatJob($job->fresh())]);
    }

    public function retry(Request $request, IngestRecord $job): JsonResponse
    {
        PermissionService::require($request->user(), 'knowledge.view');

        if ($job->status !== 'failed') {
            return response()->json(['error' => 'Only failed jobs can be retried.'], 422);
        }

        // Re-upload the file if it was cleaned up, or just re-dispatch if it still exists.
        if (! Storage::exists($job->storage_path ?? '')) {
            return response()->json(['error' => 'Temporary file no longer available. Please re-upload.'], 422);
        }

        $job->update(['status' => 'pending', 'error_message' => null, 'chunk_count' => null, 'document_count' => null]);
        KnowledgeIngestJob::dispatch($job->id);

        return response()->json(['job' => $this->formatJob($job->fresh())]);
    }

    public function destroy(Request $request, IngestRecord $job): JsonResponse
    {
        PermissionService::require($request->user(), 'knowledge.view');

        if (in_array($job->status, ['pending', 'processing'], true)) {
            return response()->json(['error' => 'Cancel the job before deleting it.'], 422);
        }

        if ($job->storage_path && Storage::exists($job->storage_path)) {
            Storage::delete($job->storage_path);
        }

        $job->delete();

        return response()->json(['ok' => true]);
    }

    private function formatJob(IngestRecord $job): array
    {
        return [
            'id'                => $job->id,
            'collection'        => $job->collection,
            'original_filename' => $job->original_filename,
            'file_size'         => $job->file_size,
            'status'            => $job->status,
            'document_count'    => $job->document_count,
            'chunk_count'       => $job->chunk_count,
            'duration_ms'       => $job->duration_ms,
            'error_message'     => $job->error_message,
            'metadata'          => $job->metadata,
            'created_at'        => $job->created_at?->toIso8601String(),
            'updated_at'        => $job->updated_at?->toIso8601String(),
        ];
    }
}
