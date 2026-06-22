<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\YoutubeSongSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YoutubeSongSearchTest extends TestCase
{
    use RefreshDatabase;

    private function fakeItem(string $id, string $title, string $channel = 'Ch'): array
    {
        return [
            'id' => ['videoId' => $id],
            'snippet' => [
                'title' => $title, 'channelTitle' => $channel,
                'thumbnails' => ['medium' => ['url' => "https://img/{$id}.jpg"]],
                'publishedAt' => '2024-01-01T00:00:00Z',
            ],
        ];
    }

    public function test_blocklisted_results_are_filtered_out(): void
    {
        config(['services.youtube.key' => 'test-key']);

        // A sermon-scope block category — the same list song search must honour.
        Setting::set('content_filter_categories', json_encode([[
            'id' => 'occult', 'label' => 'Occult', 'scope' => 'sermon', 'type' => 'block',
            'keywords' => ['tarot'],
        ]]));

        Http::fake([
            'www.googleapis.com/*' => Http::response(['items' => [
                $this->fakeItem('aaaaaaaaaaa', 'Way Maker (Live Worship)'),
                $this->fakeItem('bbbbbbbbbbb', 'Tarot reading and worship'),
            ]]),
        ]);

        $results = app(YoutubeSongSearchService::class)->search('worship');

        $ids = array_column($results, 'video_id');
        $this->assertContains('aaaaaaaaaaa', $ids);
        $this->assertNotContains('bbbbbbbbbbb', $ids, 'blocklisted result removed');
    }

    public function test_unconfigured_key_reports_not_configured(): void
    {
        config(['services.youtube.key' => null]);
        $this->assertFalse(app(YoutubeSongSearchService::class)->isConfigured());
    }
}
