<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\HistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Spiritual Journal: owner-scoped generation/list/delete, async pending→ready fill,
 * and survival of the source session's deletion.
 */
class JournalTest extends TestCase
{
    use RefreshDatabase;

    private function ownedSession(User $user): ChatSession
    {
        return app(HistoryService::class)->startSession($user, 'bible_study', ['title' => 'Psalm 23']);
    }

    public function test_generate_creates_pending_entry_and_enqueues_job(): void
    {
        Redis::shouldReceive('rpush')->once();
        $me = $this->makeUser();
        $session = $this->ownedSession($me);

        $this->actingAs($me, 'sanctum')
            ->postJson("/api/history/{$session->id}/journal")
            ->assertStatus(202)
            ->assertJsonPath('entry.status', 'pending');

        $this->assertDatabaseHas('journal_entries', [
            'user_id' => $me->id, 'chat_session_id' => $session->id, 'status' => 'pending',
        ]);
    }

    public function test_cannot_generate_from_another_users_session(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $session = $this->ownedSession($owner);

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/history/{$session->id}/journal")
            ->assertNotFound();
    }

    public function test_index_and_show_are_owner_scoped(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser();
        $mine = JournalEntry::create(['user_id' => $me->id, 'status' => 'ready', 'title' => 'Mine']);
        $theirs = JournalEntry::create(['user_id' => $other->id, 'status' => 'ready', 'title' => 'Theirs']);

        $res = $this->actingAs($me, 'sanctum')->getJson('/api/journal')->assertOk()->json();
        $this->assertCount(1, $res['entries']);
        $this->assertSame('Mine', $res['entries'][0]['title']);

        $this->actingAs($me, 'sanctum')->getJson("/api/journal/{$theirs->id}")->assertNotFound();
        $this->actingAs($me, 'sanctum')->getJson("/api/journal/{$mine->id}")->assertOk();
    }

    public function test_webhook_fills_entry_and_it_survives_session_deletion(): void
    {
        // Ensure a strong worker secret is present for the HMAC check in this env.
        config(['services.worker.secret' => str_repeat('k', 48)]);

        $me = $this->makeUser();
        $session = $this->ownedSession($me);
        $entry = JournalEntry::create([
            'user_id' => $me->id, 'chat_session_id' => $session->id, 'status' => 'pending',
        ]);

        // Signed worker callback fills the reflection.
        $body = json_encode([
            'mode' => 'journal', 'journal_entry_id' => $entry->id,
            'title' => 'The Lord is my Shepherd', 'scripture_ref' => 'Psalm 23',
            'insight' => 'You rested in God’s provision.', 'prayer' => 'Thank you, Shepherd.',
            'reflection' => 'Where do you need to trust Him today?',
        ]);
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts . '.' . $body, config('services.worker.secret'));

        $this->call('POST', '/api/internal/history-callback', [], [], [], [
            'CONTENT_TYPE'            => 'application/json',
            'HTTP_X_WORKER_TIMESTAMP' => $ts,
            'HTTP_X_WORKER_SIGNATURE' => $sig,
        ], $body)->assertOk();

        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id, 'status' => 'ready']);
        $this->assertSame('Psalm 23', $entry->fresh()->scripture_ref);

        // Deleting the source session must NOT remove the journal entry (nullOnDelete).
        $session->forceDelete();
        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id, 'chat_session_id' => null]);
    }
}
