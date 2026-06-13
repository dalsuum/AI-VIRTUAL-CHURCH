<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin Laravel client for the local FastAPI Tedim LLM service (workers/api.py).
 * Laravel orchestrates (liturgy plan, DB, Vue API); Python owns inference.
 *
 * A second cache layer in Laravel keeps Vue page-load translations instant —
 * the Python side already caches in Redis db 2, but the Laravel file cache
 * avoids the Redis round-trip on repeated page loads.
 */
class TedimLlmService
{
    private string $base;

    public function __construct()
    {
        $this->base = config('services.tedim_llm.url', 'http://127.0.0.1:8001');
    }

    public function translateToTedim(string $english): string
    {
        $key = 'tedim:' . sha1($english);

        return Cache::remember($key, now()->addDays(30), function () use ($english) {
            return Http::timeout(600)
                ->retry(2, 5000)
                ->post("{$this->base}/tedim/translate", [
                    'text'      => $english,
                    'direction' => 'en2zo',
                ])
                ->throw()
                ->json('text');
        });
    }

    /**
     * Exact Tedim verse from the local Lai Siangtho corpus — no LLM involved.
     * Returns null when the reference is outside the 1932 corpus coverage.
     */
    public function verseToTedim(string $ref): ?string
    {
        return Cache::remember('tedim:verse:' . sha1($ref), now()->addDays(90), function () use ($ref) {
            $resp = Http::timeout(30)
                ->get("{$this->base}/tedim/verse", ['ref' => $ref, 'lang' => 'td']);

            if ($resp->status() === 404) {
                return null;
            }

            return $resp->throw()->json('text');
        });
    }

    public function generateDevotional(string $prompt, int $maxTokens = 512): string
    {
        return Http::timeout(600)
            ->post("{$this->base}/tedim/generate", [
                'prompt'     => $prompt,
                'max_tokens' => $maxTokens,
            ])
            ->throw()
            ->json('text');
    }
}
