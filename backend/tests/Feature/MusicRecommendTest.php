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
