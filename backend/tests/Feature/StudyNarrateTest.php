<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Optional Text-to-Speech for AI Bible Study replies. The endpoint reuses the chapter
 * narration pipeline + per-language voice mapping and proxies to the worker; here the
 * worker HTTP call is faked so we assert auth, voice resolution and the URL passthrough.
 */
class StudyNarrateTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/v1/study/narrate', ['lang' => 'en', 'text' => 'Hi'])
            ->assertUnauthorized();
    }

    public function test_narrates_reply_with_per_language_voice(): void
    {
        Http::fake(['*/bible/narrate-text' => Http::response(['url' => 'https://cdn/test.mp3'], 200)]);

        $res = $this->actingAs($this->makeUser(), 'sanctum')
            ->postJson('/api/v1/study/narrate', ['lang' => 'en', 'text' => 'God is love.'])
            ->assertOk()
            ->json();

        $this->assertSame('https://cdn/test.mp3', $res['url']);

        // English default is edge_tts → the English Edge voice rides along in the worker call.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/bible/narrate-text')
            && $req['mode'] === 'edge_tts'
            && $req['text'] === 'God is love.'
            && str_starts_with($req['voice'], 'en-'));
    }

    public function test_rejects_empty_text(): void
    {
        $this->actingAs($this->makeUser(), 'sanctum')
            ->postJson('/api/v1/study/narrate', ['lang' => 'en', 'text' => ''])
            ->assertStatus(422);
    }
}
