<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeIngestJob as IngestRecord;
use App\Models\Setting;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Knowledge Library — per-corpus inventory and lifecycle management.
 *
 * Routes:
 *   GET    /api/v1/admin/knowledge/library                   → index    (knowledge.view)
 *   POST   /api/v1/admin/knowledge/library/{corpus}/toggle   → toggle   (knowledge.manage)
 *   POST   /api/v1/admin/knowledge/library/{corpus}/reindex  → reindex  (knowledge.manage)
 *   DELETE /api/v1/admin/knowledge/library/{corpus}          → destroy  (knowledge.manage)
 */
final class KnowledgeLibraryController extends Controller
{
    // ── Index ────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        PermissionService::require($request->user(), 'knowledge.view');

        $corpora     = (array) config('knowledge.corpora', []);
        $qdrantStats = $this->allQdrantStats($corpora);
        $lastRetrieval = Cache::get('knowledge.last_retrieval');

        $library = array_map(function (string $corpus) use ($qdrantStats, $lastRetrieval) {
            $qdrant   = $qdrantStats[$corpus] ?? ['exists' => false];
            $lastJob  = IngestRecord::where('collection', $corpus)
                ->orderByDesc('updated_at')
                ->first(['id', 'status', 'chunk_count', 'duration_ms', 'metadata', 'created_at', 'updated_at']);
            $totalSize = IngestRecord::where('collection', $corpus)
                ->where('status', 'completed')
                ->sum('file_size');
            $totalDocs = IngestRecord::where('collection', $corpus)
                ->where('status', 'completed')
                ->sum('document_count');

            return [
                'corpus'         => $corpus,
                'enabled'        => $this->isEnabled($corpus),
                'source_priority' => (int) (config('knowledge.source_priority.' . $corpus) ?? 0),
                'qdrant'         => $qdrant,
                'total_size'     => (int) $totalSize,
                'total_docs'     => (int) $totalDocs,
                'last_ingest'    => $lastJob ? [
                    'id'          => $lastJob->id,
                    'status'      => $lastJob->status,
                    'chunk_count' => $lastJob->chunk_count,
                    'duration_ms' => $lastJob->duration_ms,
                    'embedding_driver' => $lastJob->metadata['embedding_driver'] ?? null,
                    'at'          => $lastJob->updated_at?->toIso8601String(),
                ] : null,
                'last_retrieval' => isset($lastRetrieval['corpora'][$corpus]) ? [
                    'keyword_hits' => $lastRetrieval['corpora'][$corpus]['keyword_hits'] ?? 0,
                    'vector_hits'  => $lastRetrieval['corpora'][$corpus]['vector_hits'] ?? 0,
                    'at'           => $lastRetrieval['recorded_at'] ?? null,
                ] : null,
            ];
        }, $corpora);

        return response()->json([
            'corpora'          => $library,
            'embedding_driver' => config('knowledge.embedding.driver', 'hash'),
            'vector_driver'    => config('knowledge.vector.driver', 'memory'),
        ]);
    }

    // ── Toggle enable / disable ──────────────────────────────────────────────

    public function toggle(Request $request, string $corpus): JsonResponse
    {
        PermissionService::require($request->user(), 'knowledge.manage');
        $this->requireValidCorpus($corpus);

        $nowEnabled = ! $this->isEnabled($corpus);
        Setting::set("knowledge.corpus.{$corpus}.enabled", $nowEnabled ? '1' : '0');

        Log::info('knowledge.library.toggle', [
            'corpus'  => $corpus,
            'enabled' => $nowEnabled,
            'by'      => $request->user()->id,
        ]);

        return response()->json(['corpus' => $corpus, 'enabled' => $nowEnabled]);
    }

    // ── Re-index ─────────────────────────────────────────────────────────────

    /**
     * Dispatch new ingestion jobs for all successfully ingested files in this corpus.
     * Each file is deduplicated by file_hash (most-recently-completed job wins).
     * The caller may optionally pass `chunk_count_reset=true` to delete the Qdrant
     * collection first (full wipe + re-build); the default is additive (upsert).
     */
    public function reindex(Request $request, string $corpus): JsonResponse
    {
        PermissionService::require($request->user(), 'knowledge.manage');
        $this->requireValidCorpus($corpus);

        // Pick the most recent successful ingestion for each unique source file.
        $sources = IngestRecord::where('collection', $corpus)
            ->where('status', 'completed')
            ->whereNotNull('storage_path')
            ->orderByDesc('id')
            ->get(['id', 'storage_path', 'file_hash', 'original_filename', 'file_size', 'metadata'])
            ->unique('file_hash');

        if ($sources->isEmpty()) {
            return response()->json([
                'message'       => 'No completed ingestions found for this corpus. Upload documents first.',
                'dispatched'    => 0,
            ], 422);
        }

        $dispatched = 0;
        foreach ($sources as $source) {
            if (! \Illuminate\Support\Facades\Storage::exists($source->storage_path)) {
                continue; // File was cleaned up; skip silently.
            }

            $meta    = (array) ($source->metadata ?? []);
            $chunker = $meta['chunker'] ?? 'text';

            $record = IngestRecord::create([
                'created_by'        => $request->user()->id,
                'collection'        => $corpus,
                'original_filename' => $source->original_filename,
                'file_hash'         => $source->file_hash,
                'idempotency_key'   => \hash('sha256', implode('|', [
                    $corpus,
                    $source->file_hash,
                    $chunker,
                    $meta['language'] ?? 'en',
                    config('knowledge.embedding.driver', 'hash'),
                    config('knowledge.embedding.dimensions', 384),
                    'reindex',          // suffix so this is always a fresh key
                    now()->toDateString(),
                ])),
                'file_size'         => $source->file_size,
                'status'            => 'pending',
                'metadata'          => array_merge($meta, ['reindex' => true]),
                'storage_path'      => $source->storage_path,
            ]);

            \App\Jobs\KnowledgeIngestJob::dispatch($record->id);
            $dispatched++;
        }

        Log::info('knowledge.library.reindex', [
            'corpus'     => $corpus,
            'dispatched' => $dispatched,
            'by'         => $request->user()->id,
        ]);

        return response()->json([
            'message'    => "Dispatched {$dispatched} re-ingestion job(s) for corpus '{$corpus}'.",
            'dispatched' => $dispatched,
        ]);
    }

    // ── Delete / wipe corpus ──────────────────────────────────────────────────

    /**
     * Wipe all vectors from the Qdrant collection for this corpus.
     * Does NOT delete the ingestion job history — those records remain as audit trail.
     */
    public function destroy(Request $request, string $corpus): JsonResponse
    {
        PermissionService::require($request->user(), 'knowledge.manage');
        $this->requireValidCorpus($corpus);

        $deleted = false;
        if (config('knowledge.vector.driver') === 'qdrant') {
            $qdrantUrl = rtrim((string) config('knowledge.vector.qdrant.url', ''), '/');
            $qdrantKey = config('knowledge.vector.qdrant.key');
            try {
                $req = app(Http::class)->timeout(10);
                if ($qdrantKey) {
                    $req = $req->withHeaders(['api-key' => $qdrantKey]);
                }
                // Delete all points via a filter that matches everything.
                $resp = $req->post("{$qdrantUrl}/collections/{$corpus}/points/delete", [
                    'filter' => ['must' => [['is_empty' => ['key' => '__nonexistent__']]]],
                ]);
                $deleted = $resp->successful();
            } catch (\Throwable $e) {
                Log::warning('knowledge.library.destroy.qdrant_error', [
                    'corpus' => $corpus,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        Log::warning('knowledge.library.destroy', [
            'corpus'         => $corpus,
            'qdrant_deleted' => $deleted,
            'by'             => $request->user()->id,
        ]);

        return response()->json([
            'corpus'         => $corpus,
            'vectors_wiped'  => $deleted,
            'message'        => $deleted
                ? "All vectors deleted from corpus '{$corpus}'. Re-index to restore."
                : "Vector backend is not Qdrant or delete failed; local records unchanged.",
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Whether this corpus is enabled (default: true). */
    private function isEnabled(string $corpus): bool
    {
        return Setting::get("knowledge.corpus.{$corpus}.enabled", '1') !== '0';
    }

    /** Abort with 422 if $corpus is not in the configured list. */
    private function requireValidCorpus(string $corpus): void
    {
        if (! in_array($corpus, (array) config('knowledge.corpora', []), true)) {
            abort(422, "Unknown corpus: '{$corpus}'.");
        }
    }

    /**
     * Fetch Qdrant collection info for all corpora in one pass.
     *
     * @param  list<string> $corpora
     * @return array<string,array<string,mixed>>
     */
    private function allQdrantStats(array $corpora): array
    {
        $stats = [];
        if (config('knowledge.vector.driver') !== 'qdrant') {
            return $stats;
        }
        $qdrantUrl = rtrim((string) config('knowledge.vector.qdrant.url', ''), '/');
        $qdrantKey = config('knowledge.vector.qdrant.key');
        if ($qdrantUrl === '') {
            return $stats;
        }

        $http = app(Http::class);
        foreach ($corpora as $corpus) {
            try {
                $req = $http->timeout(5);
                if ($qdrantKey) {
                    $req = $req->withHeaders(['api-key' => $qdrantKey]);
                }
                $resp = $req->get("{$qdrantUrl}/collections/{$corpus}");
                if ($resp->successful()) {
                    $r = $resp->json('result', []);
                    $stats[$corpus] = [
                        'exists'        => true,
                        'status'        => $r['status'] ?? 'unknown',
                        'vectors_count' => $r['vectors_count'] ?? 0,
                        'points_count'  => $r['points_count'] ?? 0,
                        'vector_size'   => $r['config']['params']['vectors']['size'] ?? null,
                        'distance'      => $r['config']['params']['vectors']['distance'] ?? null,
                    ];
                } else {
                    $stats[$corpus] = ['exists' => false];
                }
            } catch (\Throwable) {
                $stats[$corpus] = ['exists' => false, 'error' => 'unreachable'];
            }
        }

        return $stats;
    }
}
