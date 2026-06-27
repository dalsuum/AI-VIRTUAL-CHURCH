<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\ChatSessionShare;
use App\Models\User;
use App\Services\HistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the unified-history contract: strict owner-scoping, date grouping, search,
 * soft-delete + restore, share-link expiry/password, and profile updates.
 */
class HistoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(User $user, array $attrs = []): ChatSession
    {
        return app(HistoryService::class)->startSession($user, $attrs['session_type'] ?? 'pastor', $attrs);
    }

    public function test_history_index_only_returns_callers_sessions(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser();
        $this->makeSession($me, ['title' => 'Mine']);
        $this->makeSession($other, ['title' => 'Theirs']);

        $res = $this->actingAs($me)->getJson('/api/history')->assertOk()->json();

        $titles = collect($res['groups'])->flatten(1)->pluck('title');
        $this->assertTrue($titles->contains('Mine'));
        $this->assertFalse($titles->contains('Theirs'));
    }

    public function test_cannot_view_another_users_session(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $session = $this->makeSession($owner, ['title' => 'Private']);

        $this->actingAs($intruder, 'sanctum')->getJson("/api/history/{$session->id}")->assertNotFound();
        $this->actingAs($owner, 'sanctum')->getJson("/api/history/{$session->id}")->assertOk()
            ->assertJsonPath('session.title', 'Private');
    }

    public function test_sessions_are_grouped_by_date_bucket(): void
    {
        $me = $this->makeUser();
        $this->makeSession($me, ['title' => 'Now'])->forceFill(['last_activity_at' => now()])->save();
        $this->makeSession($me, ['title' => 'Old'])->forceFill(['last_activity_at' => now()->subDays(40)])->save();

        $res = $this->actingAs($me)->getJson('/api/history')->assertOk()->json();

        $this->assertArrayHasKey('Today', $res['groups']);
        $this->assertArrayHasKey('Older', $res['groups']);
    }

    public function test_search_matches_title(): void
    {
        $me = $this->makeUser();
        $this->makeSession($me, ['title' => 'Finding Peace in Psalm 91']);
        $this->makeSession($me, ['title' => 'Worship for Joy']);

        $res = $this->actingAs($me)->postJson('/api/history/search', ['q' => 'Peace'])
            ->assertOk()->json();

        $this->assertCount(1, $res['results']);
        $this->assertStringContainsString('Peace', $res['results'][0]['title']);
    }

    public function test_soft_delete_then_restore(): void
    {
        $me = $this->makeUser();
        $session = $this->makeSession($me, ['title' => 'Removable']);

        $this->actingAs($me)->deleteJson("/api/history/{$session->id}")->assertOk();
        $this->assertSoftDeleted('chat_sessions', ['id' => $session->id]);

        $this->actingAs($me)->postJson("/api/history/{$session->id}/restore")->assertOk();
        $this->assertDatabaseHas('chat_sessions', ['id' => $session->id, 'deleted_at' => null]);
    }

    public function test_rename_and_pin_via_patch(): void
    {
        $me = $this->makeUser();
        $session = $this->makeSession($me, ['title' => 'Old name']);

        $this->actingAs($me)->patchJson("/api/history/{$session->id}", [
            'title' => 'New name', 'pinned' => true,
        ])->assertOk()->assertJsonPath('session.title', 'New name');

        $this->assertDatabaseHas('chat_sessions', ['id' => $session->id, 'pinned' => true]);
    }

    public function test_pinning_is_capped(): void
    {
        $me = $this->makeUser();
        // Seed 19 already-pinned, then exercise the endpoint at the boundary (20th is
        // allowed) and over it (21st is rejected) — both through the real route.
        for ($i = 0; $i < 19; $i++) {
            $this->makeSession($me)->forceFill(['pinned' => true])->save();
        }
        $twentieth = $this->makeSession($me);
        $twentyFirst = $this->makeSession($me);

        // Boundary: pinning the 20th succeeds.
        $this->actingAs($me, 'sanctum')
            ->patchJson("/api/history/{$twentieth->id}", ['pinned' => true])
            ->assertOk();
        $this->assertDatabaseHas('chat_sessions', ['id' => $twentieth->id, 'pinned' => true]);

        // Overflow: the 21st is rejected and stays unpinned.
        $this->actingAs($me, 'sanctum')
            ->patchJson("/api/history/{$twentyFirst->id}", ['pinned' => true])
            ->assertStatus(422);
        $this->assertDatabaseHas('chat_sessions', ['id' => $twentyFirst->id, 'pinned' => false]);
    }

    public function test_share_link_respects_expiry_and_password(): void
    {
        $me = $this->makeUser();
        $session = $this->makeSession($me, ['title' => 'Shared']);

        $res = $this->actingAs($me)->postJson("/api/history/{$session->id}/share", [
            'password' => 'secret1', 'expires_in' => 24,
        ])->assertOk()->json();
        $token = $res['token'];

        // Wrong / missing password is forbidden; correct password works (public route).
        $this->getJson("/api/shared/{$token}")->assertForbidden();
        $this->getJson("/api/shared/{$token}?password=secret1")->assertOk()
            ->assertJsonPath('session.title', 'Shared');

        // Expired link is not found.
        ChatSessionShare::query()->update(['expires_at' => now()->subHour()]);
        $this->getJson("/api/shared/{$token}?password=secret1")->assertNotFound();
    }

    public function test_profile_update_persists_preferences(): void
    {
        $me = $this->makeUser();

        $this->actingAs($me)->patchJson('/api/me/profile', [
            'fav_language' => 'td', 'ai_memory_enabled' => false,
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $me->id, 'fav_language' => 'td', 'ai_memory_enabled' => false,
        ]);
    }

    /**
     * Regression: the Pastor Chat messages endpoint reads message-type session_nodes
     * (Phase 4) — the dropped ChatSession::messages() relation 500'd, leaving the UI
     * stuck on the "…" typing indicator because the assistant turn never loaded.
     */
    public function test_pastor_messages_endpoint_returns_node_messages(): void
    {
        $me = $this->makeUser();
        $session = $this->makeSession($me, ['session_type' => 'pastor']);
        $history = app(HistoryService::class);
        $history->recordMessage($session, 'user', 'Hello pastor');
        $history->recordMessage($session, 'assistant', 'Peace be with you.');

        $res = $this->actingAs($me)->getJson("/api/pastor/sessions/{$session->id}/messages")
            ->assertOk()->json();

        $this->assertCount(2, $res['messages']);
        $this->assertSame('assistant', $res['messages'][1]['sender']);
        $this->assertSame('Peace be with you.', $res['messages'][1]['content']);
    }

    private function postHistoryCallback(array $payload): \Illuminate\Testing\TestResponse
    {
        config(['services.worker.secret' => str_repeat('k', 48)]);
        $body = json_encode($payload);
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts . '.' . $body, config('services.worker.secret'));

        return $this->call('POST', '/api/internal/history-callback', [], [], [], [
            'CONTENT_TYPE'            => 'application/json',
            'HTTP_X_WORKER_TIMESTAMP' => $ts,
            'HTTP_X_WORKER_SIGNATURE' => $sig,
        ], $body);
    }

    /** Auto Detect: the worker-resolved language is locked onto an 'auto' session. */
    public function test_pastor_reply_callback_locks_detected_language_on_auto_session(): void
    {
        $session = $this->makeSession($this->makeUser(), ['language' => 'auto']);

        $this->postHistoryCallback([
            'mode' => 'pastor_reply', 'session_id' => (string) $session->id,
            'detected_language' => 'td',
        ])->assertOk();

        $this->assertSame('td', $session->fresh()->language);
    }

    /** A session already locked to a concrete language is never overwritten by a later detection. */
    public function test_pastor_reply_callback_does_not_overwrite_concrete_language(): void
    {
        $session = $this->makeSession($this->makeUser(), ['language' => 'en']);

        $this->postHistoryCallback([
            'mode' => 'pastor_reply', 'session_id' => (string) $session->id,
            'detected_language' => 'td',
        ])->assertOk();

        $this->assertSame('en', $session->fresh()->language);
    }
}
