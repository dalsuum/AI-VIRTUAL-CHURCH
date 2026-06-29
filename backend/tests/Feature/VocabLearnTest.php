<?php

namespace Tests\Feature;

use App\Models\VocabEntry;
use App\Models\Vocabulary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Learner vocabulary: a curated concept is rendered into any supported language by the
 * AI worker and cached. First request enqueues generation (202); the signed worker
 * callback fills the cache; a later request serves it without re-enqueuing.
 */
class VocabLearnTest extends TestCase
{
    use RefreshDatabase;

    private function seedWord(): Vocabulary
    {
        return Vocabulary::create(['zolai' => 'hehpihna', 'english' => 'grace', 'category' => 'Theology']);
    }

    public function test_first_request_enqueues_generation_and_caches_pending_row(): void
    {
        Redis::shouldReceive('rpush')
            ->once()
            ->withArgs(fn ($queue, $json) => $queue === 'ai:history'
                && str_contains($json, '"mode":"vocab_generate"')
                && str_contains($json, '"language":"ja"')
                && str_contains($json, '"concept":"grace"'));

        $word = $this->seedWord();

        $this->getJson("/api/vocabulary/{$word->id}/learn?lang=ja")
            ->assertStatus(202)
            ->assertJsonPath('status', 'generating');

        $this->assertDatabaseHas('vocab_entries', [
            'vocabulary_id' => $word->id, 'language' => 'ja', 'payload' => null,
        ]);
    }

    public function test_unsupported_language_is_rejected(): void
    {
        $word = $this->seedWord();
        $this->getJson("/api/vocabulary/{$word->id}/learn?lang=xx")->assertStatus(422);
    }

    public function test_hebrew_is_not_a_learner_target(): void
    {
        // Hebrew is a Bible/reference locale only — not offered for AI generation.
        $word = $this->seedWord();
        $this->getJson("/api/vocabulary/{$word->id}/learn?lang=he")->assertStatus(422);
    }

    public function test_webhook_fills_cache_and_next_request_serves_it_without_enqueue(): void
    {
        config(['services.worker.secret' => str_repeat('k', 48)]);
        $word = $this->seedWord();
        VocabEntry::create(['vocabulary_id' => $word->id, 'language' => 'ja']);

        $body = json_encode([
            'mode' => 'vocab_entry', 'vocabulary_id' => $word->id, 'language' => 'ja',
            'word' => '恵み', 'difficulty' => 'beginner',
            'payload' => ['word' => '恵み', 'meaning' => '神の恵み'],
        ]);
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts . '.' . $body, config('services.worker.secret'));

        $this->call('POST', '/api/internal/history-callback', [], [], [], [
            'CONTENT_TYPE'            => 'application/json',
            'HTTP_X_WORKER_TIMESTAMP' => $ts,
            'HTTP_X_WORKER_SIGNATURE' => $sig,
        ], $body)->assertOk();

        $this->assertDatabaseHas('vocab_entries', [
            'vocabulary_id' => $word->id, 'language' => 'ja', 'word' => '恵み', 'difficulty' => 'beginner',
        ]);

        // Cache is warm now: serve it directly, no new job enqueued.
        Redis::shouldReceive('rpush')->never();
        $this->getJson("/api/vocabulary/{$word->id}/learn?lang=ja")
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('entry.payload.meaning', '神の恵み');
    }
}
