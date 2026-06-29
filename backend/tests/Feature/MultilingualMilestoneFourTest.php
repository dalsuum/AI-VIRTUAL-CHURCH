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

class MultilingualMilestoneFourTest extends TestCase
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

    public function test_arabic_and_hebrew_are_enabled_rtl_interface_languages(): void
    {
        $languages = $this->getJson('/api/languages')
            ->assertOk()
            ->json('languages');

        $this->assertTrue($languages['ar']['rtl']);
        $this->assertSame('ar-SA', $languages['ar']['tts_locale']);
        $this->assertTrue($languages['he']['rtl']);
        $this->assertSame('he-IL', $languages['he']['tts_locale']);
    }

    public function test_church_service_intake_accepts_rtl_languages_and_unicode_custom_moods(): void
    {
        Queue::fake();

        $user = $this->makeUser([
            'subscription_plan' => 'member',
            'token_balance' => 10,
        ]);

        $this->actingAs($user, 'sanctum');

        foreach (['ar' => 'سلام ورجاء', 'he' => 'שלום ותקווה'] as $language => $customMood) {
            $token = $this->postJson('/api/service/start')
                ->assertCreated()
                ->json('session_token');

            $this->postJson("/api/service/{$token}/intake", [
                'mood' => 'Hopeful',
                'language' => $language,
                'custom_mood' => $customMood,
                'prayer_text' => 'Please guide my family today. John 3:16',
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

    public function test_service_settings_accept_rtl_modes_and_reject_unsupported_mms(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/admin/settings', [
                'narration_mode_ar' => 'edge_tts',
                'narration_mode_he' => 'edge_tts',
                'lang_ar' => true,
                'lang_he' => true,
            ])
            ->assertOk()
            ->assertJsonPath('narration_mode_ar', 'edge_tts')
            ->assertJsonPath('narration_mode_he', 'edge_tts')
            ->assertJsonPath('lang_ar', true)
            ->assertJsonPath('lang_he', true);

        $this->assertSame('edge_tts', Setting::narrationMode('ar'));
        $this->assertSame('edge_tts', Setting::narrationMode('he'));

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/admin/settings', ['narration_mode_ar' => 'mms_tts'])
            ->assertUnprocessable();
    }

    public function test_special_day_current_preview_and_manual_content_supports_rtl_languages(): void
    {
        Cache::flush();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-05 12:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-07-05 12:00:00'));

        $admin = $this->admin();
        $create = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/special-sundays', [
                'key' => 'rtl_grace_sunday',
                'rule_type' => 'fixed',
                'rule' => ['month' => 7, 'day' => 5],
                'titles' => [
                    'en' => 'RTL Grace Sunday',
                    'ar' => 'أحد النعمة',
                    'he' => 'יום ראשון של חסד',
                ],
                'briefs' => [
                    'en' => 'A Sunday focused on grace.',
                    'ar' => 'أحد يركز على نعمة الله.',
                    'he' => 'יום ראשון המתמקד בחסד אלוהים.',
                ],
                'sermon_tags' => ['grace'],
                'music_moods' => ['peace'],
                'content_modes' => [
                    'sermon' => ['ar' => 'manual', 'he' => 'manual'],
                    'music' => ['ar' => 'manual', 'he' => 'manual'],
                ],
                'priority' => 90,
                'active' => true,
            ])
            ->assertCreated()
            ->json('observance');

        $special = SpecialSunday::findOrFail($create['id']);

        $this->getJson('/api/special-sunday/current?language=ar')
            ->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('observance.language', 'ar')
            ->assertJsonPath('observance.title', 'أحد النعمة');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/special-sundays/{$special->id}/sermons", [
                'language' => 'he',
                'title' => 'חסד רב',
                'body' => 'אלוהים מקבל אותנו בחסד רב. John 3:16 נשאר קריא.',
                'mood' => 'peace',
                'priority' => 50,
                'active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('sermon.language', 'he');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/special-sundays/{$special->id}/songs", [
                'language' => 'he',
                'title' => 'שיר חסד',
                'source_type' => 'youtube',
                'source_ref' => 'dQw4w9WgXcQ',
                'lyrics' => 'חסד על חסד',
                'mood' => 'peace',
                'priority' => 50,
                'active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('song.language', 'he');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/special-sundays/{$special->id}/preview?language=he&mood=peace")
            ->assertOk()
            ->assertJsonPath('language', 'he')
            ->assertJsonPath('title', 'יום ראשון של חסד')
            ->assertJsonPath('sermon.mode', 'manual')
            ->assertJsonPath('sermon.title', 'חסד רב')
            ->assertJsonPath('music.mode', 'manual')
            ->assertJsonPath('music.title', 'שיר חסד');
    }

    public function test_music_pools_and_worship_radio_catalog_accept_rtl_languages(): void
    {
        $admin = $this->admin();

        $musicTrack = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/music-tracks', [
                'mood' => 'Peace',
                'language' => 'ar',
                'provider_ref' => 'suno:milestone-ar',
                'storage_key' => 'music/milestone-ar.mp3',
                'title' => 'ترنيمة سلام',
                'lyrics' => 'سلام المسيح يملأ القلب',
                'source' => 'suno',
            ])
            ->assertCreated()
            ->assertJsonPath('track.language', 'ar')
            ->json('track');

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/admin/music-tracks/' . $musicTrack['id'], ['language' => 'he'])
            ->assertOk()
            ->assertJsonPath('track.language', 'he');

        $worshipTrack = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/worship-tracks', [
                'title' => 'שלום המשיח',
                'artist' => 'Hebrew Worship Team',
                'language' => 'he',
                'genre' => 'worship',
                'themes' => ['peace'],
                'moods' => ['relax'],
                'active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('track.language', 'he')
            ->json('track');

        $this->assertSame('he', MusicTrack::findOrFail($musicTrack['id'])->language);
        $this->assertSame('he', WorshipTrack::findOrFail($worshipTrack['id'])->language);
    }

    public function test_worker_callback_detection_moods_and_bible_study_register_rtl_languages(): void
    {
        config(['services.worker.secret' => str_repeat('w', 48)]);

        $this->withHeaders(['X-Worker-Secret' => config('services.worker.secret')])
            ->postJson('/api/internal/music-track', [
                'mood' => 'relax',
                'language' => 'ar',
                'provider_ref' => 'suno:worker-ar',
                'storage_key' => 'music/worker-ar.mp3',
                'title' => 'ترنيمة الرجاء',
                'lyrics' => 'رجاء وسلام في المسيح',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $detector = new HeuristicLanguageDetector(['en', 'ar', 'he']);
        $this->assertSame('ar', $detector->detect('أريد أن أصلي من أجل عائلتي.'));
        $this->assertSame('he', $detector->detect('אני רוצה להתפלל עבור המשפחה שלי.'));

        $arabicThemes = app(MoodExpansionService::class)->expand('سلام');
        $hebrewThemes = app(MoodExpansionService::class)->expand('שלום');
        $this->assertContains('peace', $arabicThemes);
        $this->assertContains('comfort', $arabicThemes);
        $this->assertContains('peace', $hebrewThemes);
        $this->assertContains('comfort', $hebrewThemes);

        $this->seed(BibleStudySeeder::class);
        $manifest = ModuleManifest::where('key', 'bible_study')->firstOrFail();

        foreach (['ar', 'he'] as $code) {
            $this->assertContains($code, $manifest->languages);
            $this->assertDatabaseHas('ai_personas', ['module' => 'bible_study', 'language' => $code]);
            $this->assertDatabaseHas('ai_prompt_templates', ['module' => 'bible_study', 'language' => $code, 'role' => 'summary']);
        }
    }
}
