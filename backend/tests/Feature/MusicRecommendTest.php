<?php

namespace Tests\Feature;

use App\Models\WorshipTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MusicRecommendTest extends TestCase
{
    use RefreshDatabase;

    /** Seed a spread of tracks across languages so scoring has something to rank. */
    private function seedCatalog(): void
    {
        foreach (['en', 'my', 'td'] as $lang) {
            for ($i = 1; $i <= 8; $i++) {
                WorshipTrack::create([
                    'title'    => "{$lang} song {$i}",
                    'artist'   => "{$lang} artist {$i}",
                    'language' => $lang,
                    'themes'   => ['peace', 'trust', 'comfort'],
                    'moods'    => ['anxiety', 'peace'],
                    'popularity' => $i * 5,
                    'active'   => true,
                ]);
            }
        }
    }

    public function test_blocked_channel_track_is_not_served(): void
    {
        \App\Models\Setting::set('content_filter_categories', json_encode([[
            'id' => 'offtopic', 'label' => 'Off-topic Channels', 'scope' => 'both', 'type' => 'block',
            'keywords' => ['suan khan mang'],
        ]]));

        WorshipTrack::create([
            'title' => 'Topa Muangin', 'artist' => 'Suan Khan Mang', 'language' => 'td',
            'youtube_url' => 'https://www.youtube.com/watch?v=zzzzzzzzzzz',
            'themes' => ['peace'], 'moods' => ['peace'], 'popularity' => 99, 'active' => true,
        ]);
        WorshipTrack::create([
            'title' => 'Pasian Phatna', 'artist' => 'Clean Choir', 'language' => 'td',
            'themes' => ['peace'], 'moods' => ['peace'], 'popularity' => 10, 'active' => true,
        ]);

        $res = $this->postJson('/api/music/recommend', ['language' => 'td', 'mood' => 'Peace']);

        $res->assertOk();
        $artists = array_column($res->json('playlist'), 'artist');
        $this->assertNotContains('Suan Khan Mang', $artists, 'blocked channel filtered at serve time');
    }

    public function test_recommend_returns_between_5_and_10_tracks(): void
    {
        $this->seedCatalog();

        $res = $this->postJson('/api/music/recommend', [
            'language' => 'my',
            'mood'     => 'Anxiety',
        ]);

        $res->assertOk()
            ->assertJsonStructure(['playlist', 'reason', 'themes']);

        $playlist = $res->json('playlist');
        $this->assertGreaterThanOrEqual(5, count($playlist));
        $this->assertLessThanOrEqual(10, count($playlist));
    }

    public function test_burmese_request_never_returns_english_first(): void
    {
        $this->seedCatalog();

        $res = $this->postJson('/api/music/recommend', [
            'language' => 'my',
            'mood'     => 'Anxiety',
        ]);

        $this->assertSame('my', $res->json('playlist.0.language'));
    }

    public function test_request_stays_in_language_when_enough_tracks_exist(): void
    {
        // 8 Burmese tracks comfortably exceed the min of 5, so no English/Zolai
        // should leak into a Burmese request even though more songs exist.
        $this->seedCatalog();

        $langs = array_column(
            $this->postJson('/api/music/recommend', ['language' => 'my', 'mood' => 'Anxiety'])->json('playlist'),
            'language',
        );

        $this->assertSame(['my'], array_values(array_unique($langs)));
    }

    public function test_language_is_a_hard_filter_never_cross_fills(): void
    {
        // Only 2 Zolai tracks exist and plenty of English: a Zolai request must
        // return ONLY those 2 Zolai tracks — never pad with English, even though
        // that leaves the playlist below the usual minimum. (The client loops
        // within the language instead.)
        WorshipTrack::create(['title' => 'td one', 'language' => 'td', 'moods' => ['anxiety'], 'active' => true]);
        WorshipTrack::create(['title' => 'td two', 'language' => 'td', 'moods' => ['anxiety'], 'active' => true]);
        foreach (range(1, 6) as $i) {
            WorshipTrack::create(['title' => "en {$i}", 'language' => 'en', 'moods' => ['anxiety'], 'active' => true]);
        }

        $playlist = $this->postJson('/api/music/recommend', ['language' => 'td', 'mood' => 'Anxiety'])->json('playlist');

        $this->assertCount(2, $playlist);
        $this->assertSame(['td'], array_values(array_unique(array_column($playlist, 'language'))));
    }

    public function test_continuation_excluding_all_same_language_returns_empty_not_other_language(): void
    {
        // Simulate the "kept pressing Next" case: exclude every English id. The
        // server must NOT substitute Burmese/Zolai — it returns an empty list and
        // the client recycles within English.
        $this->seedCatalog();
        $allEnglish = WorshipTrack::where('language', 'en')->pluck('id')->all();

        $playlist = $this->postJson('/api/music/recommend', [
            'language' => 'en',
            'mood'     => 'Anxiety',
            'exclude'  => $allEnglish,
        ])->json('playlist');

        $this->assertSame([], $playlist, 'no other-language tracks leak in when English is exhausted');
    }

    public function test_exclude_ids_are_not_returned(): void
    {
        $this->seedCatalog();
        $excluded = WorshipTrack::where('language', 'my')->pluck('id')->take(3)->all();

        $res = $this->postJson('/api/music/recommend', [
            'language' => 'my',
            'mood'     => 'Anxiety',
            'exclude'  => $excluded,
        ]);

        $returned = array_column($res->json('playlist'), 'id');
        $this->assertEmpty(array_intersect($excluded, $returned));
    }

    public function test_empty_catalog_language_falls_back_to_broad_youtube_query(): void
    {
        // Zolai catalogue is empty and the narrow native query returns nothing —
        // discovery must broaden to a proven term, persist the hits, and serve
        // them instead of reporting "no songs". Mock YouTube so no network is hit.
        $yt = \Mockery::mock(\App\Services\YoutubeSongSearchService::class);
        $yt->shouldReceive('isConfigured')->andReturn(true);
        $yt->shouldReceive('search')->andReturnUsing(function (string $query) {
            // Narrow mood-specific query finds nothing; the broad fallback does.
            if (! str_contains(mb_strtolower($query), 'zomi worship song')) {
                return [];
            }
            return [[
                'video_id' => 'aaaaaaaaaaa', 'url' => 'https://www.youtube.com/watch?v=aaaaaaaaaaa',
                'title' => 'Zomi Worship', 'channel' => 'Zomi Worship Team', 'thumbnail' => null,
            ]];
        });
        $this->app->instance(\App\Services\YoutubeSongSearchService::class, $yt);

        $playlist = $this->postJson('/api/music/recommend', ['language' => 'td', 'mood' => 'Peace'])->json('playlist');

        $this->assertNotEmpty($playlist, 'broad fallback query backfilled the empty Zolai catalogue');
        $this->assertSame(['td'], array_values(array_unique(array_column($playlist, 'language'))));
    }

    public function test_discovery_walks_ladder_until_it_has_a_full_playlist(): void
    {
        // First query yields a single song; the broad fallback yields more. The
        // ladder must keep going past the thin first hit and accumulate enough
        // to fill a playlist instead of stopping at one song.
        $yt = \Mockery::mock(\App\Services\YoutubeSongSearchService::class);
        $yt->shouldReceive('isConfigured')->andReturn(true);
        $yt->shouldReceive('search')->andReturnUsing(function (string $query) {
            $broad = str_contains(mb_strtolower($query), 'zomi worship song');
            $n = $broad ? 8 : 1;
            $tag = $broad ? 'b' : 'a';
            return array_map(fn ($i) => [
                'video_id' => "v{$tag}{$i}000000000", 'url' => "https://www.youtube.com/watch?v=v{$tag}{$i}00000000",
                'title' => "Zomi Worship {$tag}{$i}", 'channel' => 'Zomi Worship Team', 'thumbnail' => null,
            ], range(1, $n));
        });
        $this->app->instance(\App\Services\YoutubeSongSearchService::class, $yt);

        $playlist = $this->postJson('/api/music/recommend', ['language' => 'td', 'mood' => 'Peace'])->json('playlist');

        $this->assertGreaterThanOrEqual(5, count($playlist), 'ladder accumulated past the thin first query');
    }

    public function test_discovery_queries_are_language_aware(): void
    {
        // Capture every query the recommender sends to YouTube for a Zolai +
        // relax request: the first must carry the native mood label + a concept,
        // and the broad fallbacks must be the configured Zomi/Tedim terms.
        $seen = [];
        $yt = \Mockery::mock(\App\Services\YoutubeSongSearchService::class);
        $yt->shouldReceive('isConfigured')->andReturn(true);
        $yt->shouldReceive('search')->andReturnUsing(function (string $q) use (&$seen) {
            $seen[] = $q;
            return [];   // force the full ladder to run
        });
        $this->app->instance(\App\Services\YoutubeSongSearchService::class, $yt);

        $this->postJson('/api/music/recommend', ['language' => 'td', 'mood' => 'relax'])->assertOk();

        $this->assertStringContainsString('Lungmuanna', $seen[0], 'native Zolai mood label seeds the first query');
        $joined = implode(' | ', $seen);
        $this->assertStringContainsString('Zomi worship song', $joined, 'configured broad fallback is tried');
        $this->assertStringContainsString('Tedim worship song', $joined);
    }

    public function test_moods_endpoint_exposes_the_six_universal_categories(): void
    {
        $res = $this->getJson('/api/music/moods')->assertOk();
        $keys = array_column($res->json('moods'), 'key');

        $this->assertSame(['energy', 'feel_good', 'focus', 'love', 'relax', 'heartbreak'], $keys);
        // Each chip carries an emoji + a translated label set for the switcher.
        $relax = collect($res->json('moods'))->firstWhere('key', 'relax');
        $this->assertSame('🌿', $relax['emoji']);
        $this->assertSame('Lungmuanna', $relax['labels']['td']);
    }

    public function test_discovery_rejects_foreign_script_titles(): void
    {
        // An English search returns a Hindi (Devanagari) upload; it must NOT be
        // persisted as `en`. A clean English result from the same batch is kept.
        $yt = \Mockery::mock(\App\Services\YoutubeSongSearchService::class);
        $yt->shouldReceive('isConfigured')->andReturn(true);
        $yt->shouldReceive('search')->andReturn([
            ['video_id' => 'hindivideo0', 'url' => 'https://www.youtube.com/watch?v=hindivideo0',
             'title' => 'आराधना स्तुति गीत CHRISTIAN WORSHIP', 'channel' => 'Jesus Songs', 'thumbnail' => null],
            ['video_id' => 'englishvid0', 'url' => 'https://www.youtube.com/watch?v=englishvid0',
             'title' => 'Goodness of God Worship', 'channel' => 'Bethel', 'thumbnail' => null],
        ]);
        $this->app->instance(\App\Services\YoutubeSongSearchService::class, $yt);

        $titles = array_column(
            $this->postJson('/api/music/recommend', ['language' => 'en', 'mood' => 'relax'])->json('playlist'),
            'title',
        );

        $this->assertContains('Goodness of God Worship', $titles);
        $this->assertNotContains('आराधना स्तुति गीत CHRISTIAN WORSHIP', $titles, 'Hindi title not served as English');
        $this->assertDatabaseMissing('worship_tracks', ['youtube_url' => 'https://www.youtube.com/watch?v=hindivideo0']);
    }

    public function test_serve_time_drops_existing_mislabelled_discovered_track(): void
    {
        // A Devanagari title was saved as `en` before the script gate existed
        // (auto-discovered = metadata_only). It must self-heal: dropped at serve
        // time without a migration. A curated en row is unaffected.
        WorshipTrack::create([
            'title' => 'आराधना स्तुति गीत', 'language' => 'en', 'moods' => ['peace'],
            'youtube_url' => 'https://www.youtube.com/watch?v=oldhindivid', 'copyright_status' => 'metadata_only',
            'popularity' => 20, 'active' => true,
        ]);
        WorshipTrack::create([
            'title' => 'Amazing Grace', 'language' => 'en', 'moods' => ['peace'], 'popularity' => 50, 'active' => true,
        ]);

        $titles = array_column(
            $this->postJson('/api/music/recommend', ['language' => 'en', 'mood' => 'relax'])->json('playlist'),
            'title',
        );

        $this->assertContains('Amazing Grace', $titles);
        $this->assertNotContains('आराधना स्तुति गीत', $titles);
    }

    public function test_burmese_script_is_kept_for_my_requests(): void
    {
        WorshipTrack::create([
            'title' => 'အေးချမ်းခြင်း ဓမ္မသီချင်း', 'language' => 'my', 'moods' => ['peace'],
            'youtube_url' => 'https://www.youtube.com/watch?v=burmesevid0', 'copyright_status' => 'metadata_only',
            'popularity' => 20, 'active' => true,
        ]);

        $titles = array_column(
            $this->postJson('/api/music/recommend', ['language' => 'my', 'mood' => 'relax'])->json('playlist'),
            'title',
        );

        $this->assertContains('အေးချမ်းခြင်း ဓမ္မသီချင်း', $titles, 'Myanmar script allowed for my');
    }

    public function test_invalid_language_is_rejected(): void
    {
        $this->postJson('/api/music/recommend', [
            'language' => 'fr',
            'mood'     => 'Anxiety',
        ])->assertStatus(422);
    }

    public function test_moods_endpoint_lists_options(): void
    {
        $this->getJson('/api/music/moods')
            ->assertOk()
            ->assertJsonStructure(['moods' => [['key', 'label', 'emoji']]]);
    }
}
