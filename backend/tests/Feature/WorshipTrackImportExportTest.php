<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorshipTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorshipTrackImportExportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return $this->makeUser(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
    }

    public function test_import_creates_tracks_and_normalizes_legacy_moods(): void
    {
        $res = $this->actingAs($this->admin())->postJson('/api/admin/worship-tracks/import', [
            'tracks' => [[
                'title' => 'Build My Life', 'artist' => 'Pat Barrett', 'language' => 'en',
                'moods' => ['peace', 'hope'], // legacy trigger words -> "relax"
                'themes' => ['faith', 'trust'],
                'youtube' => 'https://www.youtube.com/watch?v=aaaaaaaaaaa',
                'license' => 'Official YouTube', 'active' => true,
            ]],
        ]);

        $res->assertOk()->assertJson(['imported' => 1, 'skipped' => 0]);

        $track = WorshipTrack::firstWhere('title', 'Build My Life');
        $this->assertSame(['relax'], $track->moods, 'legacy mood words collapse to the canonical id');
        $this->assertSame('https://www.youtube.com/watch?v=aaaaaaaaaaa', $track->youtube_url);
        $this->assertSame('Official YouTube', $track->copyright_status);
    }

    public function test_import_skips_duplicates_and_rejects_invalid_rows(): void
    {
        WorshipTrack::create([
            'title' => 'Broken Vessels', 'artist' => 'Hillsong Worship', 'language' => 'en',
            'youtube_url' => 'https://www.youtube.com/watch?v=bbbbbbbbbbb', 'active' => true,
        ]);

        $res = $this->actingAs($this->admin())->postJson('/api/admin/worship-tracks/import', [
            'tracks' => [
                // exact duplicate (title+artist+youtube) -> skipped
                ['title' => 'Broken Vessels', 'artist' => 'Hillsong Worship', 'language' => 'en',
                 'youtube' => 'https://www.youtube.com/watch?v=bbbbbbbbbbb'],
                // bad language -> rejected
                ['title' => 'Bad Lang', 'artist' => 'X', 'language' => 'xx'],
                // unknown mood -> rejected
                ['title' => 'Bad Mood', 'artist' => 'Y', 'language' => 'en', 'moods' => ['xyzzy']],
                // valid new row -> imported
                ['title' => 'New Song', 'artist' => 'Z', 'language' => 'en'],
            ],
        ]);

        $res->assertOk()->assertJson(['imported' => 1, 'skipped' => 1]);
        $this->assertCount(2, $res->json('errors'));
        $this->assertSame(1, WorshipTrack::where('title', 'New Song')->count());
        $this->assertSame(1, WorshipTrack::where('title', 'Broken Vessels')->count(), 'not inserted twice');
    }

    public function test_export_round_trips_into_import(): void
    {
        WorshipTrack::create([
            'title' => 'It Is Well', 'artist' => 'Bethel', 'language' => 'en',
            'moods' => ['relax'], 'themes' => ['peace'],
            'youtube_url' => 'https://www.youtube.com/watch?v=ccccccccccc', 'active' => true,
        ]);

        $admin = $this->admin();

        $exported = $this->actingAs($admin)->getJson('/api/admin/worship-tracks/export')
            ->assertOk()->json('tracks');

        $this->assertSame('https://www.youtube.com/watch?v=ccccccccccc', $exported[0]['youtube']);

        // Re-importing the exact export is a no-op (all duplicates).
        $this->actingAs($admin)->postJson('/api/admin/worship-tracks/import', ['tracks' => $exported])
            ->assertOk()->assertJson(['imported' => 0, 'skipped' => 1]);
    }

    public function test_import_requires_permission(): void
    {
        $this->actingAs($this->makeUser())
            ->postJson('/api/admin/worship-tracks/import', ['tracks' => [['title' => 'x', 'artist' => 'y', 'language' => 'en']]])
            ->assertForbidden();
    }
}
