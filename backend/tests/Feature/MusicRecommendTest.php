<?php

namespace Tests\Feature;

use App\Models\WorshipTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MusicRecommendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Live YouTube results are cached per (language, mood); flush so a mocked
        // result pool from one test can't leak into the next.
        Cache::flush();
    }

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

    /** Mock the YouTube service to return $rows for queries matching $broadNeedle (else []). */
    private function mockYoutube(callable $handler): void
    {
        $yt = \Mockery::mock(\App\Services\YoutubeSongSearchService::class);
        $yt->shouldReceive('isConfigured')->andReturn(true);
        $yt->shouldReceive('search')->andReturnUsing($handler);
        $this->app->instance(\App\Services\YoutubeSongSearchService::class, $yt);
    }

    public function test_empty_catalog_language_serves_live_youtube_without_persisting(): void
    {
        // Zolai catalogue is empty and the narrow native query returns nothing —
        // the live fallback must broaden to a proven term and SERVE the hits, but
        // never write them to the database (catalogue stays curated-only).
        $this->mockYoutube(function (string $query) {
            if (! str_contains(mb_strtolower($query), 'zomi worship song')) {
                return [];
            }
            return [[
                'video_id' => 'aaaaaaaaaaa', 'url' => 'https://www.youtube.com/watch?v=aaaaaaaaaaa',
                'title' => 'Zomi Worship', 'channel' => 'Zomi Worship Team', 'thumbnail' => null,
            ]];
        });

        $playlist = $this->postJson('/api/music/recommend', ['language' => 'td', 'mood' => 'relax'])->json('playlist');

        $this->assertNotEmpty($playlist, 'live fallback backfilled the empty Zolai catalogue');
        $this->assertSame(['td'], array_values(array_unique(array_column($playlist, 'language'))));
        $this->assertSame(0, WorshipTrack::count(), 'live results are NOT persisted');
        $this->assertLessThan(0, $playlist[0]['id'], 'live track carries a negative synthetic id');
    }

    public function test_live_fallback_walks_ladder_until_it_has_a_full_playlist(): void
    {
        // First query yields one song; the broad fallback yields more. The ladder
        // keeps going past the thin first hit to fill the playlist.
        $this->mockYoutube(function (string $query) {
            $broad = str_contains(mb_strtolower($query), 'zomi worship song');
            $n = $broad ? 8 : 1;
            $tag = $broad ? 'b' : 'a';
            return array_map(fn ($i) => [
                'video_id' => "v{$tag}{$i}000000000", 'url' => "https://www.youtube.com/watch?v=v{$tag}{$i}00000000",
                'title' => "Zomi Worship {$tag}{$i}", 'channel' => 'Zomi Worship Team', 'thumbnail' => null,
            ], range(1, $n));
        });

        $playlist = $this->postJson('/api/music/recommend', ['language' => 'td', 'mood' => 'relax'])->json('playlist');

        $this->assertGreaterThanOrEqual(5, count($playlist), 'ladder accumulated past the thin first query');
        $this->assertSame(0, WorshipTrack::count());
    }

    public function test_curated_catalogue_ranks_before_live_results(): void
    {
        // One curated Zolai track exists; live fills the rest. The curated song
        // must come first, and the live ones (negative ids) after.
        WorshipTrack::create([
            'title' => 'Curated Zolai', 'language' => 'td', 'themes' => ['peace'], 'moods' => ['peace'],
            'popularity' => 90, 'active' => true,
        ]);
        $this->mockYoutube(fn (string $q) => str_contains(mb_strtolower($q), 'zomi worship song')
            ? array_map(fn ($i) => [
                'video_id' => "live{$i}000000", 'url' => "https://www.youtube.com/watch?v=live{$i}0000000",
                'title' => "Live Zolai {$i}", 'channel' => 'Zomi Worship Team', 'thumbnail' => null,
            ], range(1, 8))
            : []);

        $playlist = $this->postJson('/api/music/recommend', ['language' => 'td', 'mood' => 'relax'])->json('playlist');

        $this->assertSame('Curated Zolai', $playlist[0]['title'], 'curated song ranks first');
        $this->assertGreaterThan(0, $playlist[0]['id'], 'curated track keeps its real DB id');
        $this->assertLessThan(0, $playlist[1]['id'], 'live tracks follow with synthetic ids');
        $this->assertSame(1, WorshipTrack::count(), 'only the curated row exists');
    }

    public function test_live_track_is_excluded_by_its_synthetic_id(): void
    {
        $url = 'https://www.youtube.com/watch?v=excludeme00';
        $this->mockYoutube(fn (string $q) => str_contains(mb_strtolower($q), 'zomi worship song')
            ? [['video_id' => 'excludeme00', 'url' => $url, 'title' => 'Live Zolai', 'channel' => 'Zomi Worship Team', 'thumbnail' => null]]
            : []);

        // crc32-derived negative id (mirrors MusicRecommendationService::liveId()).
        $syntheticId = -((int) (sprintf('%u', crc32($url)) % 2000000000)) - 1;

        $ids = array_column(
            $this->postJson('/api/music/recommend', ['language' => 'td', 'mood' => 'relax', 'exclude' => [$syntheticId]])->json('playlist'),
            'id',
        );

        $this->assertNotContains($syntheticId, $ids, 'a recently-played live track is not served again');
    }

    public function test_live_results_are_cached_not_refetched_within_ttl(): void
    {
        $calls = 0;
        $this->mockYoutube(function (string $q) use (&$calls) {
            $calls++;
            return str_contains(mb_strtolower($q), 'zomi worship song')
                ? [['video_id' => 'cachedvid00', 'url' => 'https://www.youtube.com/watch?v=cachedvid00',
                    'title' => 'Cached Zolai', 'channel' => 'Zomi Worship Team', 'thumbnail' => null]]
                : [];
        });

        $this->postJson('/api/music/recommend', ['language' => 'td', 'mood' => 'relax'])->assertOk();
        $afterFirst = $calls;
        $this->postJson('/api/music/recommend', ['language' => 'td', 'mood' => 'relax'])->assertOk();

        $this->assertGreaterThan(0, $afterFirst, 'first request hits YouTube');
        $this->assertSame($afterFirst, $calls, 'second request within TTL reuses the cached pool');
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

    public function test_live_fallback_rejects_foreign_script_titles(): void
    {
        // An English search returns a Hindi (Devanagari) upload alongside a clean
        // English one. The Hindi result is dropped (never served as `en`); the
        // English one plays. Nothing is persisted either way.
        $this->mockYoutube(fn (string $q) => [
            ['video_id' => 'hindivideo0', 'url' => 'https://www.youtube.com/watch?v=hindivideo0',
             'title' => 'आराधना स्तुति गीत CHRISTIAN WORSHIP', 'channel' => 'Jesus Songs', 'thumbnail' => null],
            ['video_id' => 'englishvid0', 'url' => 'https://www.youtube.com/watch?v=englishvid0',
             'title' => 'Goodness of God Worship', 'channel' => 'Bethel', 'thumbnail' => null],
        ]);

        $titles = array_column(
            $this->postJson('/api/music/recommend', ['language' => 'en', 'mood' => 'relax'])->json('playlist'),
            'title',
        );

        $this->assertContains('Goodness of God Worship', $titles);
        $this->assertNotContains('आराधना स्तुति गीत CHRISTIAN WORSHIP', $titles, 'Hindi title not served as English');
        $this->assertSame(0, WorshipTrack::count());
    }

    public function test_live_fallback_keeps_burmese_script_for_my(): void
    {
        $this->mockYoutube(fn (string $q) => [
            ['video_id' => 'burmesevid0', 'url' => 'https://www.youtube.com/watch?v=burmesevid0',
             'title' => 'အေးချမ်းခြင်း ဓမ္မသီချင်း', 'channel' => 'Myanmar Worship', 'thumbnail' => null],
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
