<?php

namespace Tests\Feature;

use App\Models\ModuleManifest;
use App\Models\MusicTrack;
use App\Models\Setting;
use App\Models\SpecialSunday;
use App\Models\User;
use App\Models\WorshipTrack;
use App\Services\Chat\Support\HeuristicLanguageDetector;
use App\Services\MoodExpansionService;
use Carbon\CarbonImmutable;
use Database\Seeders\BibleStudySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MultilingualMilestoneThreeTest extends TestCase
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

    public function test_interface_language_registry_exposes_milestone_three_service_locales(): void
    {
        $languages = $this->getJson('/api/languages')
            ->assertOk()
            ->json('languages');

        foreach (['en', 'my', 'td', 'fr', 'de', 'es', 'ja', 'zh-CN', 'ko', 'hi', 'ta', 'th'] as $code) {
            $this->assertArrayHasKey($code, $languages);
        }

        $this->assertArrayHasKey('ar', $languages);
        $this->assertArrayHasKey('he', $languages);
    }

    public function test_church_service_intake_accepts_indic_and_thai_languages_and_unicode_custom_moods(): void
    {
        Queue::fake();

        $user = $this->makeUser([
            'subscription_plan' => 'member',
            'token_balance' => 10,
        ]);

        $this->actingAs($user, 'sanctum');

        foreach (['hi' => 'शांति', 'ta' => 'சமாதானம்', 'th' => 'สันติสุข'] as $language => $customMood) {
            $token = $this->postJson('/api/service/start')
                ->assertCreated()
                ->json('session_token');

            $this->postJson("/api/service/{$token}/intake", [
                'mood' => 'Hopeful',
                'language' => $language,
                'custom_mood' => $customMood,
                'prayer_text' => 'Please guide my family today.',
            ])
                ->assertAccepted()
                ->assertJsonPath('status', 'active');

            $this->assertDatabaseHas('service_sessions', [
                'session_token' => $token,
                'language' => $language,
                'status' => 'active',
            ]);
            $this->assertDatabaseHas('service_intakes', [
                'custom_mood' => $customMood,
            ]);
        }
    }

    public function test_service_settings_accept_milestone_three_modes_and_reject_unsupported_mms(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/admin/settings', [
                'narration_mode_hi' => 'edge_tts',
                'narration_mode_ta' => 'openai',
                'narration_mode_th' => 'off',
                'lang_hi' => true,
                'lang_ta' => true,
                'lang_th' => true,
            ])
            ->assertOk()
            ->assertJsonPath('narration_mode_hi', 'edge_tts')
            ->assertJsonPath('narration_mode_ta', 'openai')
            ->assertJsonPath('narration_mode_th', 'off')
            ->assertJsonPath('lang_hi', true)
            ->assertJsonPath('lang_ta', true)
            ->assertJsonPath('lang_th', true);

        $this->assertSame('edge_tts', Setting::narrationMode('hi'));
        $this->assertSame('openai', Setting::narrationMode('ta'));
        $this->assertSame('off', Setting::narrationMode('th'));

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/admin/settings', ['narration_mode_hi' => 'mms_tts'])
            ->assertUnprocessable();
    }

    public function test_special_day_current_preview_and_manual_content_supports_tamil(): void
    {
        Cache::flush();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-28 12:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-06-28 12:00:00'));

        $admin = $this->admin();
        $create = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/special-sundays', [
                'key' => 'tamil_grace_sunday',
                'rule_type' => 'fixed',
                'rule' => ['month' => 6, 'day' => 28],
                'titles' => [
                    'en' => 'Tamil Grace Sunday',
                    'ta' => 'கிருபை ஞாயிறு',
                    'hi' => '',
                ],
                'briefs' => [
                    'en' => 'A Sunday focused on grace.',
                    'ta' => 'கிருபையை மையமாகக் கொண்ட ஞாயிறு.',
                    'hi' => '',
                ],
                'sermon_tags' => ['grace'],
                'music_moods' => ['joy'],
                'content_modes' => [
                    'sermon' => ['ta' => 'manual'],
                    'music' => ['ta' => 'manual'],
                ],
                'priority' => 90,
                'active' => true,
            ])
            ->assertCreated()
            ->json('observance');

        $special = SpecialSunday::findOrFail($create['id']);
        $this->assertArrayNotHasKey('hi', $special->titles);
        $this->assertArrayNotHasKey('hi', $special->briefs);

        $this->getJson('/api/special-sunday/current?language=ta')
            ->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('observance.language', 'ta')
            ->assertJsonPath('observance.title', 'கிருபை ஞாயிறு')
            ->assertJsonPath('observance.brief', 'கிருபையை மையமாகக் கொண்ட ஞாயிறு.');

        $this->getJson('/api/special-sunday/current?language=hi')
            ->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('observance.language', 'en')
            ->assertJsonPath('observance.title', 'Tamil Grace Sunday')
            ->assertJsonPath('observance.brief', 'A Sunday focused on grace.');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/special-sundays/{$special->id}/sermons", [
                'language' => 'ta',
                'title' => 'பெருகும் கிருபை',
                'body' => 'தேவன் பெருகும் கிருபையால் நம்மை ஏற்றுக்கொள்கிறார்.',
                'mood' => 'joy',
                'priority' => 50,
                'active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('sermon.language', 'ta');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/special-sundays/{$special->id}/songs", [
                'language' => 'ta',
                'title' => 'கிருபையின் பாடல்',
                'source_type' => 'youtube',
                'source_ref' => 'dQw4w9WgXcQ',
                'lyrics' => 'கிருபைக்கு மேலாக கிருபை',
                'mood' => 'joy',
                'priority' => 50,
                'active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('song.language', 'ta');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/special-sundays/{$special->id}/preview?language=ta&mood=joy")
            ->assertOk()
            ->assertJsonPath('language', 'ta')
            ->assertJsonPath('title', 'கிருபை ஞாயிறு')
            ->assertJsonPath('sermon.mode', 'manual')
            ->assertJsonPath('sermon.title', 'பெருகும் கிருபை')
            ->assertJsonPath('music.mode', 'manual')
            ->assertJsonPath('music.title', 'கிருபையின் பாடல்');
    }

    public function test_music_pools_and_worship_radio_catalog_accept_milestone_three_languages(): void
    {
        $admin = $this->admin();

        $musicTrack = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/music-tracks', [
                'mood' => 'Joyful',
                'language' => 'hi',
                'provider_ref' => 'suno:milestone-hi',
                'storage_key' => 'music/milestone-hi.mp3',
                'title' => 'आनंद की आराधना',
                'lyrics' => 'हल्लेलूयाह प्रभु की स्तुति',
                'source' => 'suno',
            ])
            ->assertCreated()
            ->assertJsonPath('track.language', 'hi')
            ->json('track');

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/admin/music-tracks/' . $musicTrack['id'], ['language' => 'th'])
            ->assertOk()
            ->assertJsonPath('track.language', 'th');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/music-tracks?language=th')
            ->assertOk()
            ->assertJsonCount(1, 'tracks')
            ->assertJsonPath('tracks.0.language', 'th');

        $worshipTrack = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/worship-tracks', [
                'title' => 'สันติสุขของพระคริสต์',
                'artist' => 'Thai Worship Team',
                'language' => 'th',
                'genre' => 'worship',
                'themes' => ['peace'],
                'moods' => ['relax'],
                'active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('track.language', 'th')
            ->json('track');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/worship-tracks?language=th')
            ->assertOk()
            ->assertJsonCount(1, 'tracks')
            ->assertJsonPath('tracks.0.language', 'th');

        $this->assertSame('th', MusicTrack::findOrFail($musicTrack['id'])->language);
        $this->assertSame('th', WorshipTrack::findOrFail($worshipTrack['id'])->language);
    }

    public function test_worker_music_track_callback_preserves_milestone_three_language(): void
    {
        config(['services.worker.secret' => str_repeat('w', 48)]);

        $this->withHeaders(['X-Worker-Secret' => config('services.worker.secret')])
            ->postJson('/api/internal/music-track', [
                'mood' => 'relax',
                'language' => 'ta',
                'provider_ref' => 'suno:worker-ta',
                'storage_key' => 'music/worker-ta.mp3',
                'title' => 'சமாதான ஆராதனை',
                'lyrics' => 'இயேசுவின் சமாதானம்',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('music_tracks', [
            'provider_ref' => 'suno:worker-ta',
            'language' => 'ta',
        ]);
    }

    public function test_pastor_chat_detection_and_worship_moods_handle_indic_and_thai_text(): void
    {
        $detector = new HeuristicLanguageDetector(['en', 'my', 'td', 'fr', 'de', 'es', 'ja', 'zh-CN', 'ko', 'hi', 'ta', 'th']);

        $this->assertSame('hi', $detector->detect('मैं परिवार के लिए प्रार्थना करना चाहता हूँ।'));
        $this->assertSame('ta', $detector->detect('நான் குடும்பத்திற்காக ஜெபிக்க விரும்புகிறேன்.'));
        $this->assertSame('th', $detector->detect('ฉันอยากอธิษฐานเผื่อครอบครัว'));
        $this->assertSame('hi', $detector->detect('please pray with me', 'hi-IN'));

        $hindiThemes = app(MoodExpansionService::class)->expand('शांति');
        $thaiThemes = app(MoodExpansionService::class)->expand('สันติสุข');

        $this->assertContains('peace', $hindiThemes);
        $this->assertContains('comfort', $hindiThemes);
        $this->assertContains('peace', $thaiThemes);
        $this->assertContains('comfort', $thaiThemes);
    }

    public function test_bible_study_seeder_registers_milestone_three_languages_personas_and_templates(): void
    {
        $this->seed(BibleStudySeeder::class);

        $manifest = ModuleManifest::where('key', 'bible_study')->firstOrFail();

        foreach (['hi', 'ta', 'th'] as $code) {
            $this->assertContains($code, $manifest->languages);
            $this->assertDatabaseHas('ai_personas', ['module' => 'bible_study', 'language' => $code]);
            $this->assertDatabaseHas('ai_prompt_templates', ['module' => 'bible_study', 'language' => $code, 'role' => 'summary']);
        }
    }
}
