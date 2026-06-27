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

    private function fakeItem(string $id, string $title, string $channel = 'Ch', string $channelId = 'UCdefault'): array
    {
        return [
            'id' => ['videoId' => $id],
            'snippet' => [
                'title' => $title, 'channelTitle' => $channel, 'channelId' => $channelId,
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

    public function test_block_by_channel_id_or_url(): void
    {
        config(['services.youtube.key' => 'test-key']);

        // Admin pastes a channel id (or its URL) as a block term.
        Setting::set('content_filter_categories', json_encode([[
            'id' => 'banned_channels', 'label' => 'Banned Channels', 'scope' => 'sermon', 'type' => 'block',
            'keywords' => ['ucbadchannel'],
        ]]));

        Http::fake([
            'www.googleapis.com/*' => Http::response(['items' => [
                $this->fakeItem('aaaaaaaaaaa', 'Way Maker (Live Worship)', 'Good Ch', 'UCgoodchannel'),
                $this->fakeItem('bbbbbbbbbbb', 'Way Maker (Live Worship)', 'Bad Ch', 'UCbadChannel'),
            ]]),
        ]);

        $ids = array_column(app(YoutubeSongSearchService::class)->search('worship'), 'video_id');
        $this->assertContains('aaaaaaaaaaa', $ids);
        $this->assertNotContains('bbbbbbbbbbb', $ids, 'result from blocked channel id removed');
    }

    public function test_unconfigured_key_reports_not_configured(): void
    {
        config(['services.youtube.key' => null]);
        $this->assertFalse(app(YoutubeSongSearchService::class)->isConfigured());
    }
}
