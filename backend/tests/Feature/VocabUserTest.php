<?php

namespace Tests\Feature;

use App\Models\UserVocabulary;
use App\Models\VocabEntry;
use App\Models\Vocabulary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Phase C per-user vocabulary: favorites + viewed history (owner-scoped) and the cached
 * AI Explain path. Generated text is cached on the entry, never duplicated per user.
 */
class VocabUserTest extends TestCase
{
    use RefreshDatabase;

    private function seedWord(): Vocabulary
    {
        return Vocabulary::create(['zolai' => 'hehpihna', 'english' => 'grace', 'category' => 'Theology']);
    }

    public function test_favorite_toggle_is_owner_scoped_and_idempotent(): void
    {
        $me = $this->makeUser();
        $word = $this->seedWord();

        $this->actingAs($me, 'sanctum')->postJson("/api/vocabulary/{$word->id}/favorite")->assertOk();
        $this->actingAs($me, 'sanctum')->postJson("/api/vocabulary/{$word->id}/favorite")->assertOk();
        $this->assertDatabaseCount('user_vocabulary', 1);

        $list = $this->actingAs($me, 'sanctum')->getJson('/api/me/vocabulary?kind=favorite')->assertOk()->json();
        $this->assertCount(1, $list['items']);

        $this->actingAs($me, 'sanctum')->deleteJson("/api/vocabulary/{$word->id}/favorite")->assertOk();
        $this->assertDatabaseCount('user_vocabulary', 0);
    }

    public function test_favorites_require_auth(): void
    {
        $word = $this->seedWord();
        $this->postJson("/api/vocabulary/{$word->id}/favorite")->assertUnauthorized();
    }

    public function test_learn_records_viewed_history_for_registered_user(): void
    {
        Redis::shouldReceive('rpush')->andReturnNull();
        $me = $this->makeUser();
        $word = $this->seedWord();

        $this->actingAs($me, 'sanctum')->getJson("/api/vocabulary/{$word->id}/learn?lang=ja")->assertStatus(202);

        $this->assertDatabaseHas('user_vocabulary', [
            'user_id' => $me->id, 'vocabulary_id' => $word->id, 'kind' => 'viewed',
        ]);
    }

    public function test_explain_returns_cached_then_serves_without_enqueue(): void
    {
        config(['services.worker.secret' => str_repeat('k', 48)]);
        $me = $this->makeUser();
        $word = $this->seedWord();
        // A ready entry must exist before an explanation can attach to it.
        VocabEntry::create(['vocabulary_id' => $word->id, 'language' => 'ja', 'payload' => ['word' => '恵み']]);

        Redis::shouldReceive('rpush')->once();
        $this->actingAs($me, 'sanctum')->postJson("/api/vocabulary/{$word->id}/explain?lang=ja")
            ->assertStatus(202)->assertJsonPath('status', 'generating');

        // Worker callback caches the explanation.
        $body = json_encode([
            'mode' => 'vocab_explanation', 'vocabulary_id' => $word->id, 'language' => 'ja',
            'explanation' => '恵みとは神の愛です。',
        ]);
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts . '.' . $body, config('services.worker.secret'));
        $this->call('POST', '/api/internal/history-callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WORKER_TIMESTAMP' => $ts, 'HTTP_X_WORKER_SIGNATURE' => $sig,
        ], $body)->assertOk();

        // Cached: served directly, no new job.
        Redis::shouldReceive('rpush')->never();
        $this->actingAs($me, 'sanctum')->postJson("/api/vocabulary/{$word->id}/explain?lang=ja")
            ->assertOk()->assertJsonPath('status', 'ready')
            ->assertJsonPath('explanation', '恵みとは神の愛です。');
    }
}
