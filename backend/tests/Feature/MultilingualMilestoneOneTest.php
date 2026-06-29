<?php

namespace Tests\Feature;

use App\Models\MusicTrack;
use App\Models\Setting;
use App\Models\SpecialSunday;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MultilingualMilestoneOneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function admin(): User
    {
        return $this->makeUser(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
    }

    public function test_church_service_intake_accepts_milestone_one_languages(): void
    {
        Queue::fake();

        $user = $this->makeUser([
            'subscription_plan' => 'member',
            'token_balance' => 10,
        ]);

        $this->actingAs($user, 'sanctum');

        foreach (['fr', 'de', 'es'] as $language) {
            $token = $this->postJson('/api/service/start')
                ->assertCreated()
                ->json('session_token');

            $this->postJson("/api/service/{$token}/intake", [
                'mood' => 'Hopeful',
                'language' => $language,
                'prayer_text' => 'Thank you for today.',
            ])
                ->assertAccepted()
                ->assertJsonPath('status', 'active');

            $this->assertDatabaseHas('service_sessions', [
                'session_token' => $token,
                'language' => $language,
                'status' => 'active',
            ]);
        }
    }

    public function test_service_settings_accept_milestone_modes_and_reject_unsupported_mms(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/admin/settings', [
                'narration_mode_fr' => 'edge_tts',
                'narration_mode_de' => 'off',
                'narration_mode_es' => 'openai',
                'lang_fr' => true,
                'lang_de' => true,
                'lang_es' => true,
            ])
            ->assertOk()
            ->assertJsonPath('narration_mode_fr', 'edge_tts')
            ->assertJsonPath('narration_mode_de', 'off')
            ->assertJsonPath('narration_mode_es', 'openai')
            ->assertJsonPath('lang_fr', true)
            ->assertJsonPath('lang_de', true)
            ->assertJsonPath('lang_es', true);

        $this->assertSame('edge_tts', Setting::narrationMode('fr'));
        $this->assertSame('off', Setting::narrationMode('de'));
        $this->assertSame('openai', Setting::narrationMode('es'));

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/admin/settings', ['narration_mode_fr' => 'mms_tts'])
            ->assertUnprocessable();
    }

    public function test_special_day_current_preview_and_manual_content_support_french(): void
    {
        Cache::flush();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-28 12:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-06-28 12:00:00'));

        $admin = $this->admin();
        $create = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/special-sundays', [
                'key' => 'grace_sunday',
                'rule_type' => 'fixed',
                'rule' => ['month' => 6, 'day' => 28],
                'titles' => [
                    'en' => 'Grace Sunday',
                    'fr' => 'Dimanche de grâce',
                    'de' => '',
                ],
                'briefs' => [
                    'en' => 'A Sunday focused on grace.',
                    'fr' => 'Un dimanche centré sur la grâce.',
                    'de' => '',
                ],
                'sermon_tags' => ['grace'],
                'music_moods' => ['joy'],
                'content_modes' => [
                    'sermon' => ['fr' => 'manual'],
                    'music' => ['fr' => 'manual'],
                ],
                'priority' => 90,
                'active' => true,
            ])
            ->assertCreated()
            ->json('observance');

        $special = SpecialSunday::findOrFail($create['id']);
        $this->assertArrayNotHasKey('de', $special->titles);
        $this->assertArrayNotHasKey('de', $special->briefs);

        $this->getJson('/api/special-sunday/current?language=fr')
            ->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('observance.language', 'fr')
            ->assertJsonPath('observance.title', 'Dimanche de grâce')
            ->assertJsonPath('observance.brief', 'Un dimanche centré sur la grâce.');

        $this->getJson('/api/special-sunday/current?language=de')
            ->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('observance.language', 'en')
            ->assertJsonPath('observance.title', 'Grace Sunday')
            ->assertJsonPath('observance.brief', 'A Sunday focused on grace.');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/special-sundays/{$special->id}/sermons", [
                'language' => 'fr',
                'title' => 'Grâce abondante',
                'body' => 'Dieu nous accueille avec une grâce abondante.',
                'mood' => 'joy',
                'priority' => 50,
                'active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('sermon.language', 'fr');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/special-sundays/{$special->id}/songs", [
                'language' => 'fr',
                'title' => 'Chant de grâce',
                'source_type' => 'youtube',
                'source_ref' => 'dQw4w9WgXcQ',
                'lyrics' => 'Grâce sur grâce',
                'mood' => 'joy',
                'priority' => 50,
                'active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('song.language', 'fr');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/special-sundays/{$special->id}/preview?language=fr&mood=joy")
            ->assertOk()
            ->assertJsonPath('language', 'fr')
            ->assertJsonPath('title', 'Dimanche de grâce')
            ->assertJsonPath('sermon.mode', 'manual')
            ->assertJsonPath('sermon.title', 'Grâce abondante')
            ->assertJsonPath('music.mode', 'manual')
            ->assertJsonPath('music.title', 'Chant de grâce');
    }

    public function test_ai_music_pool_accepts_and_filters_milestone_one_languages(): void
    {
        $admin = $this->admin();

        $track = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/music-tracks', [
                'mood' => 'Joyful',
                'language' => 'es',
                'provider_ref' => 'suno:milestone-es',
                'storage_key' => 'music/milestone-es.mp3',
                'title' => 'Canto de alegría',
                'lyrics' => 'Aleluya',
                'source' => 'suno',
            ])
            ->assertCreated()
            ->assertJsonPath('track.language', 'es')
            ->json('track');

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/admin/music-tracks/' . $track['id'], ['language' => 'de'])
            ->assertOk()
            ->assertJsonPath('track.language', 'de');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/music-tracks?language=de')
            ->assertOk()
            ->assertJsonCount(1, 'tracks')
            ->assertJsonPath('tracks.0.language', 'de');

        $this->assertSame('de', MusicTrack::findOrFail($track['id'])->language);
    }
}
