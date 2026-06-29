<?php

namespace Tests\Feature;

use App\Models\ModuleManifest;
use App\Models\MusicTrack;
use App\Models\Setting;
use App\Models\SpecialSunday;
use App\Models\User;
use App\Models\WorshipTrack;
use App\Services\Chat\Support\HeuristicLanguageDetector;
use App\Services\HistoryService;
use App\Services\MoodExpansionService;
use Carbon\CarbonImmutable;
use Database\Seeders\BibleStudySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MultilingualMilestoneTwoTest extends TestCase
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

    private function postHistoryCallback(array $payload): \Illuminate\Testing\TestResponse
    {
        config(['services.worker.secret' => str_repeat('m', 48)]);
        $body = json_encode($payload);
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts . '.' . $body, config('services.worker.secret'));

        return $this->call('POST', '/api/internal/history-callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WORKER_TIMESTAMP' => $ts,
            'HTTP_X_WORKER_SIGNATURE' => $sig,
        ], $body);
    }

    public function test_interface_language_registry_exposes_milestone_two_service_locales(): void
    {
        $languages = $this->getJson('/api/languages')
            ->assertOk()
            ->json('languages');

        foreach (['en', 'my', 'td', 'fr', 'de', 'es', 'ja', 'zh-CN', 'ko'] as $code) {
            $this->assertArrayHasKey($code, $languages);
        }

        foreach (['ar'] as $code) {
            $this->assertArrayNotHasKey($code, $languages);
        }
    }

    public function test_church_service_intake_accepts_cjk_languages_and_unicode_custom_moods(): void
    {
        Queue::fake();

        $user = $this->makeUser([
            'subscription_plan' => 'member',
            'token_balance' => 10,
        ]);

        $this->actingAs($user, 'sanctum');

        foreach (['ja' => '希望', 'zh-CN' => '平安', 'ko' => '소망'] as $language => $customMood) {
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

    public function test_service_settings_accept_cjk_modes_and_reject_unsupported_mms(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/admin/settings', [
                'narration_mode_ja' => 'edge_tts',
                'narration_mode_zh-CN' => 'openai',
                'narration_mode_ko' => 'off',
                'lang_ja' => true,
                'lang_zh-CN' => true,
                'lang_ko' => true,
            ])
            ->assertOk()
            ->assertJsonPath('narration_mode_ja', 'edge_tts')
            ->assertJsonPath('narration_mode_zh-CN', 'openai')
            ->assertJsonPath('narration_mode_ko', 'off')
            ->assertJsonPath('lang_ja', true)
            ->assertJsonPath('lang_zh-CN', true)
            ->assertJsonPath('lang_ko', true);

        $this->assertSame('edge_tts', Setting::narrationMode('ja'));
        $this->assertSame('openai', Setting::narrationMode('zh-CN'));
        $this->assertSame('off', Setting::narrationMode('ko'));

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson('/api/admin/settings', ['narration_mode_ja' => 'mms_tts'])
            ->assertUnprocessable();
    }

    public function test_special_day_current_preview_and_manual_content_support_simplified_chinese(): void
    {
        Cache::flush();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-28 12:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-06-28 12:00:00'));

        $admin = $this->admin();
        $create = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/special-sundays', [
                'key' => 'cjk_grace_sunday',
                'rule_type' => 'fixed',
                'rule' => ['month' => 6, 'day' => 28],
                'titles' => [
                    'en' => 'CJK Grace Sunday',
                    'zh-CN' => '恩典主日',
                    'ko' => '',
                ],
                'briefs' => [
                    'en' => 'A Sunday focused on grace.',
                    'zh-CN' => '专注于恩典的主日。',
                    'ko' => '',
                ],
                'sermon_tags' => ['grace'],
                'music_moods' => ['joy'],
                'content_modes' => [
                    'sermon' => ['zh-CN' => 'manual'],
                    'music' => ['zh-CN' => 'manual'],
                ],
                'priority' => 90,
                'active' => true,
            ])
            ->assertCreated()
            ->json('observance');

        $special = SpecialSunday::findOrFail($create['id']);
        $this->assertArrayNotHasKey('ko', $special->titles);
        $this->assertArrayNotHasKey('ko', $special->briefs);

        $this->getJson('/api/special-sunday/current?language=zh-CN')
            ->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('observance.language', 'zh-CN')
            ->assertJsonPath('observance.title', '恩典主日')
            ->assertJsonPath('observance.brief', '专注于恩典的主日。');

        $this->getJson('/api/special-sunday/current?language=ko')
            ->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('observance.language', 'en')
            ->assertJsonPath('observance.title', 'CJK Grace Sunday')
            ->assertJsonPath('observance.brief', 'A Sunday focused on grace.');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/special-sundays/{$special->id}/sermons", [
                'language' => 'zh-CN',
                'title' => '丰盛恩典',
                'body' => '神以丰盛的恩典接纳我们。',
                'mood' => 'joy',
                'priority' => 50,
                'active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('sermon.language', 'zh-CN');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/special-sundays/{$special->id}/songs", [
                'language' => 'zh-CN',
                'title' => '恩典之歌',
                'source_type' => 'youtube',
                'source_ref' => 'dQw4w9WgXcQ',
                'lyrics' => '恩典上加恩典',
                'mood' => 'joy',
                'priority' => 50,
                'active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('song.language', 'zh-CN');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/special-sundays/{$special->id}/preview?language=zh-CN&mood=joy")
            ->assertOk()
            ->assertJsonPath('language', 'zh-CN')
            ->assertJsonPath('title', '恩典主日')
            ->assertJsonPath('sermon.mode', 'manual')
            ->assertJsonPath('sermon.title', '丰盛恩典')
            ->assertJsonPath('music.mode', 'manual')
            ->assertJsonPath('music.title', '恩典之歌');
    }

    public function test_ai_music_pool_accepts_and_filters_cjk_languages(): void
    {
        $admin = $this->admin();

        $track = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/music-tracks', [
                'mood' => 'Joyful',
                'language' => 'ko',
                'provider_ref' => 'suno:milestone-ko',
                'storage_key' => 'music/milestone-ko.mp3',
                'title' => '기쁨의 찬양',
                'lyrics' => '할렐루야 주님을 찬양합니다',
                'source' => 'suno',
            ])
            ->assertCreated()
            ->assertJsonPath('track.language', 'ko')
            ->json('track');

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/admin/music-tracks/' . $track['id'], ['language' => 'zh-CN'])
            ->assertOk()
            ->assertJsonPath('track.language', 'zh-CN');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/music-tracks?language=zh-CN')
            ->assertOk()
            ->assertJsonCount(1, 'tracks')
            ->assertJsonPath('tracks.0.language', 'zh-CN');

        $this->assertSame('zh-CN', MusicTrack::findOrFail($track['id'])->language);
    }

    public function test_worker_music_track_callback_preserves_canonical_cjk_language(): void
    {
        config(['services.worker.secret' => str_repeat('w', 48)]);

        $this->withHeaders(['X-Worker-Secret' => config('services.worker.secret')])
            ->postJson('/api/internal/music-track', [
                'mood' => 'relax',
                'language' => 'zh-CN',
                'provider_ref' => 'suno:worker-zh-cn',
                'storage_key' => 'music/worker-zh-cn.mp3',
                'title' => '平安敬拜',
                'lyrics' => '主赐平安',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('music_tracks', [
            'provider_ref' => 'suno:worker-zh-cn',
            'language' => 'zh-CN',
        ]);
    }

    public function test_worship_radio_catalog_accepts_and_filters_cjk_languages(): void
    {
        $admin = $this->admin();

        $track = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/worship-tracks', [
                'title' => '主的平安',
                'artist' => '测试诗班',
                'language' => 'zh-CN',
                'genre' => 'worship',
                'themes' => ['peace'],
                'moods' => ['relax'],
                'active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('track.language', 'zh-CN')
            ->json('track');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/worship-tracks?language=zh-CN')
            ->assertOk()
            ->assertJsonCount(1, 'tracks')
            ->assertJsonPath('tracks.0.language', 'zh-CN');

        $this->assertSame('zh-CN', WorshipTrack::findOrFail($track['id'])->language);
    }

    public function test_pastor_chat_detection_and_worship_moods_handle_cjk_text(): void
    {
        $detector = new HeuristicLanguageDetector(['en', 'my', 'td', 'fr', 'de', 'es', 'ja', 'zh-CN', 'ko']);

        $this->assertSame('ja', $detector->detect('今日は祈りについて相談したいです。'));
        $this->assertSame('zh-CN', $detector->detect('我想为家人祷告，求神赐平安。'));
        $this->assertSame('ko', $detector->detect('오늘 가족을 위해 기도하고 싶습니다.'));
        $this->assertSame('zh-CN', $detector->detect('please pray with me', 'zh'));

        $themes = app(MoodExpansionService::class)->expand('平安');
        $this->assertContains('peace', $themes);
        $this->assertContains('comfort', $themes);
    }

    public function test_pastor_chat_worker_callback_accepts_cjk_detected_language(): void
    {
        $session = app(HistoryService::class)->startSession($this->makeUser(), 'pastor', [
            'language' => 'auto',
        ]);

        $this->postHistoryCallback([
            'mode' => 'pastor_reply',
            'session_id' => (string) $session->id,
            'detected_language' => 'zh-CN',
        ])->assertOk();

        $this->assertSame('zh-CN', $session->fresh()->language);
    }

    public function test_bible_study_seeder_registers_cjk_languages_personas_and_templates(): void
    {
        $this->seed(BibleStudySeeder::class);

        $manifest = ModuleManifest::where('key', 'bible_study')->firstOrFail();

        $this->assertContains('ja', $manifest->languages);
        $this->assertContains('zh-CN', $manifest->languages);
        $this->assertContains('ko', $manifest->languages);
        $this->assertDatabaseHas('ai_personas', ['module' => 'bible_study', 'language' => 'ja']);
        $this->assertDatabaseHas('ai_personas', ['module' => 'bible_study', 'language' => 'zh-CN']);
        $this->assertDatabaseHas('ai_personas', ['module' => 'bible_study', 'language' => 'ko']);
        $this->assertDatabaseHas('ai_prompt_templates', ['module' => 'bible_study', 'language' => 'ko', 'role' => 'summary']);
    }
}
