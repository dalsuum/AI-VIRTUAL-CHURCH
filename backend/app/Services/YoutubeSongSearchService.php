<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Searches YouTube for embeddable worship-song uploads and screens results
 * through the SAME content filter the sermon pipeline uses
 * (Setting::filterKeywordsForScope('sermon'), which merges the 'sermon' + 'both'
 * categories). A result is dropped when its title or channel contains a block
 * keyword, unless an allow keyword is also present (allowlist overrides block,
 * mirroring the worker's scope rules).
 *
 * Used by the Worship Radio admin tool to attach clean YouTube links to catalog
 * tracks. Requires services.youtube.key (YOUTUBE_API_KEY).
 */
class YoutubeSongSearchService
{
    private const ENDPOINT = 'https://www.googleapis.com/youtube/v3/search';

    /** Content-filter scope to apply — the sermon list, per product decision. */
    private const FILTER_SCOPE = 'sermon';

    /** True when a key is configured (lets the controller 503 cleanly). */
    public function isConfigured(): bool
    {
        return ! empty(config('services.youtube.key'));
    }

    /**
     * Search YouTube and return up to $max filtered candidates, each shaped as
     * ['video_id', 'url', 'title', 'channel', 'thumbnail', 'published_at'].
     */
    public function search(string $query, int $max = 8): array
    {
        $key = (string) config('services.youtube.key');
        if ($key === '') {
            return [];
        }

        $resp = Http::timeout(15)->get(self::ENDPOINT, [
            'key'             => $key,
            'q'               => $query,
            'part'            => 'snippet',
            'type'            => 'video',
            'videoEmbeddable' => 'true',
            'safeSearch'      => 'strict',
            'maxResults'      => min(25, max($max, 10)),
        ]);

        if (! $resp->successful()) {
            return [];
        }

        $block = array_map('mb_strtolower', Setting::filterKeywordsForScope(self::FILTER_SCOPE, 'block'));
        $allow = array_map('mb_strtolower', Setting::allowKeywordsForScope(self::FILTER_SCOPE));

        $out = [];
        foreach ((array) $resp->json('items', []) as $item) {
            $id = $item['id']['videoId'] ?? null;
            if (! $id) {
                continue;
            }
            $snippet = $item['snippet'] ?? [];
            $haystack = mb_strtolower(($snippet['title'] ?? '') . ' ' . ($snippet['channelTitle'] ?? ''));

            if ($this->isBlocked($haystack, $block, $allow)) {
                continue;
            }

            $out[] = [
                'video_id'     => $id,
                'url'          => "https://www.youtube.com/watch?v={$id}",
                'title'        => $snippet['title'] ?? '',
                'channel'      => $snippet['channelTitle'] ?? '',
                'thumbnail'    => $snippet['thumbnails']['medium']['url'] ?? ($snippet['thumbnails']['default']['url'] ?? null),
                'published_at' => $snippet['publishedAt'] ?? null,
            ];
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /** A hit on a block keyword that no allow keyword rescues. */
    private function isBlocked(string $haystack, array $block, array $allow): bool
    {
        foreach ($allow as $a) {
            if ($a !== '' && str_contains($haystack, $a)) {
                return false;
            }
        }
        foreach ($block as $b) {
            if ($b !== '' && str_contains($haystack, $b)) {
                return true;
            }
        }
        return false;
    }
}
