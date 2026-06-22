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

    public function test_cross_language_fills_only_below_minimum(): void
    {
        // Only 2 Zolai tracks exist (< min 5): the playlist tops up to the min
        // with other languages rather than returning a too-short list.
        WorshipTrack::create(['title' => 'td one', 'language' => 'td', 'moods' => ['anxiety'], 'active' => true]);
        WorshipTrack::create(['title' => 'td two', 'language' => 'td', 'moods' => ['anxiety'], 'active' => true]);
        foreach (range(1, 6) as $i) {
            WorshipTrack::create(['title' => "en {$i}", 'language' => 'en', 'moods' => ['anxiety'], 'active' => true]);
        }

        $playlist = $this->postJson('/api/music/recommend', ['language' => 'td', 'mood' => 'Anxiety'])->json('playlist');

        $this->assertCount(5, $playlist);
        $this->assertSame('td', $playlist[0]['language'], 'same-language tracks rank first');
        $this->assertSame(2, count(array_filter($playlist, fn ($t) => $t['language'] === 'td')));
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
