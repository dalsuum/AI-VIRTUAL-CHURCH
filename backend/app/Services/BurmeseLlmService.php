<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin Laravel client for the local FastAPI Burmese LLM service (workers/api.py).
 * Laravel orchestrates (liturgy plan, DB, Vue API); Python owns inference.
 *
 * A second cache layer in Laravel keeps Vue page-load translations instant —
 * the Python side already caches in Redis db 3, but the Laravel file cache
 * avoids the Redis round-trip on repeated page loads.
 */
class BurmeseLlmService
{
    private string $base;

    public function __construct()
    {
        $this->base = config('services.burmese_llm.url', 'http://127.0.0.1:8002');
    }

    public function translateToBurmese(string $english): string
    {
        $key = 'burmese:' . sha1($english);

        return Cache::remember($key, now()->addDays(30), function () use ($english) {
            return Http::timeout(600)
                ->retry(2, 5000)
                ->post("{$this->base}/burmese/translate", [
                    'text'      => $english,
                    'direction' => 'en2my',
                ])
                ->throw()
                ->json('text');
        });
    }

    /**
     * Exact Myanmar verse from the local Judson 1835 corpus — no LLM involved.
     * Returns null when the reference is outside the corpus coverage.
     */
    public function verseToBurmese(string $ref): ?string
    {
        return Cache::remember('burmese:verse:' . sha1($ref), now()->addDays(90), function () use ($ref) {
            $resp = Http::timeout(30)
                ->get("{$this->base}/burmese/verse", ['ref' => $ref, 'lang' => 'my']);

            if ($resp->status() === 404) {
                return null;
            }

            return $resp->throw()->json('text');
        });
    }

    public function generateDevotional(string $prompt, int $maxTokens = 512): string
    {
        return Http::timeout(600)
            ->post("{$this->base}/burmese/generate", [
                'prompt'     => $prompt,
                'max_tokens' => $maxTokens,
            ])
            ->throw()
            ->json('text');
    }
}
