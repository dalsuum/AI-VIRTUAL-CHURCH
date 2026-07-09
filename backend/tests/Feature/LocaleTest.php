<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    public function test_locale_cookie_is_attached_to_streamed_responses(): void
    {
        // SSE endpoints (Bible Study / Pastor Chat) return a Symfony
        // StreamedResponse, which lacks Laravel's withCookie() macro. The
        // middleware must still attach the locale cookie without erroring —
        // regression: withCookie() 500'd every stream open, so the client
        // looped forever on "Reconnecting…".
        $request = Request::create('/api/v1/study/sessions/1/stream', 'GET');
        $response = (new SetLocale())->handle(
            $request,
            fn () => new StreamedResponse(fn () => null)
        );

        $names = array_map(fn ($c) => $c->getName(), $response->headers->getCookies());
        $this->assertContains('locale', $names);
    }

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
