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
