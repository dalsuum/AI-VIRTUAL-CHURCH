<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\App;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    public function test_languages_endpoint_returns_enabled_registry(): void
    {
        $res = $this->getJson('/api/languages');

        $res->assertOk()
            ->assertJsonPath('fallback', 'en')
            ->assertJsonPath('languages.en.rtl', false)
            ->assertJsonPath('languages.ta.tts_locale', 'ta-IN')
            ->assertJsonPath('languages.ar.rtl', true)
            ->assertJsonPath('languages.ar.tts_locale', 'ar-SA')
            ->assertJsonPath('languages.he.rtl', true)
            ->assertJsonPath('languages.he.tts_locale', 'he-IL');
    }

    public function test_accept_language_header_sets_app_locale(): void
    {
        $this->withHeader('Accept-Language', 'ar,en;q=0.8')->getJson('/api/languages');

        $this->assertSame('ar', App::getLocale());

        $this->withHeader('Accept-Language', 'he,en;q=0.8')->getJson('/api/languages');

        $this->assertSame('he', App::getLocale());
    }

    public function test_explicit_lang_query_overrides_and_unknown_falls_back(): void
    {
        $this->getJson('/api/languages?lang=de');
        $this->assertSame('de', App::getLocale());

        $this->getJson('/api/languages?lang=klingon');
        $this->assertSame('en', App::getLocale());
    }

    public function test_profile_rejects_unknown_fav_language(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->patchJson('/api/me/profile', ['fav_language' => 'klingon'])
            ->assertStatus(422);

        $this->actingAs($user)
            ->patchJson('/api/me/profile', ['fav_language' => 'ta'])
            ->assertOk();

        $this->actingAs($user)
            ->patchJson('/api/me/profile', ['fav_language' => 'he'])
            ->assertOk();
    }
}
