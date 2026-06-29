<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Online Bible reader's Listen button is driven by the Bible version
 * registry plus per-language TTS configuration, not by the age of a translation.
 */
class BibleNarrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_world_language_bibles_are_narratable_by_default(): void
    {
        $res = $this->getJson('/api/bible/config')
            ->assertOk()
            ->json();

        foreach (['ar', 'de', 'es', 'fr', 'hi', 'ja', 'ko', 'ta', 'th', 'zh-CN'] as $lang) {
            $this->assertTrue($res['narratable'][$lang], "{$lang} should expose Listen by default");
        }
    }

    public function test_world_language_bible_audio_uses_native_edge_voices(): void
    {
        Http::fake(['*/bible/narrate' => Http::response(['url' => 'https://cdn/bible.mp3'], 200)]);

        $expectedVoices = [
            'ar' => 'ar-SA-ZariyahNeural',
            'de' => 'de-DE-KatjaNeural',
            'es' => 'es-ES-ElviraNeural',
            'fr' => 'fr-FR-DeniseNeural',
            'hi' => 'hi-IN-SwaraNeural',
            'ja' => 'ja-JP-NanamiNeural',
            'ko' => 'ko-KR-SunHiNeural',
            'ta' => 'ta-IN-PallaviNeural',
            'th' => 'th-TH-PremwadeeNeural',
            'zh-CN' => 'zh-CN-XiaoxiaoNeural',
        ];

        foreach (array_keys($expectedVoices) as $lang) {
            $this->getJson("/api/bible/audio?lang={$lang}&book=43&chapter=3")
                ->assertOk()
                ->assertJsonPath('url', 'https://cdn/bible.mp3');
        }

        foreach ($expectedVoices as $lang => $voice) {
            Http::assertSent(fn ($req) => str_contains($req->url(), '/bible/narrate')
                && $req['lang'] === $lang
                && $req['mode'] === 'edge_tts'
                && $req['voice'] === $voice);
        }
    }

    public function test_bible_narration_modes_are_validated_by_registered_version(): void
    {
        foreach (array_keys(Setting::BIBLE_VERSIONS) as $lang) {
            $this->assertContains('edge_tts', Setting::bibleNarrationModeOptions($lang));
            $this->assertContains('off', Setting::bibleNarrationModeOptions($lang));
        }

        Setting::set('bible_narration_mode_ja', 'off');
        $this->assertSame('off', Setting::bibleNarrationMode('ja'));

        Setting::set('bible_narration_mode_ja', 'openai');
        $this->assertSame('edge_tts', Setting::bibleNarrationMode('ja'));
    }

    public function test_copyrighted_drop_in_versions_are_hidden_and_inherit_locale_narration(): void
    {
        $res = $this->getJson('/api/bible/config')
            ->assertOk()
            ->json();

        $this->assertNotContains('ja-jcb', $res['versions']);
        $this->assertNotContains('zh-CN-ccb', $res['versions']);
        $this->assertFalse($res['features']['ja-jcb']['enabled']);
        $this->assertFalse($res['features']['zh-CN-ccb']['enabled']);

        $this->assertSame('edge_tts', Setting::bibleNarrationMode('ja-jcb'));
        $this->assertSame('edge_tts', Setting::bibleNarrationMode('zh-CN-ccb'));
        $this->assertSame('ja-JP-NanamiNeural', Setting::bibleEdgeTtsVoice('ja-jcb', 'female'));
        $this->assertSame('zh-CN-XiaoxiaoNeural', Setting::bibleEdgeTtsVoice('zh-CN-ccb', 'female'));
    }
}
