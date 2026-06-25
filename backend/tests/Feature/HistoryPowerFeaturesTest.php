<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\Folder;
use App\Models\SessionNode;
use App\Services\HistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * History power features: sidebar folders, session branching (fork), owner-scoped
 * message-body search, and the staff church-analytics endpoint.
 */
class HistoryPowerFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_folder_crud_and_assignment_with_owner_scope(): void
    {
        $me = $this->makeUser();
        $session = app(HistoryService::class)->startSession($me, 'pastor', ['title' => 'Chat']);

        // Create a folder.
        $folder = $this->actingAs($me, 'sanctum')
            ->postJson('/api/folders', ['name' => 'Prayers', 'color' => '#88c'])
            ->assertCreated()->json('folder');

        // File the session into it.
        $this->actingAs($me, 'sanctum')
            ->patchJson("/api/history/{$session->id}/folder", ['folder_id' => $folder['id']])
            ->assertOk();
        $this->assertSame($folder['id'], $session->fresh()->folder_id);

        // Index lists the folder with a session count.
        $list = $this->actingAs($me, 'sanctum')->getJson('/api/folders')->assertOk()->json('folders');
        $this->assertSame(1, $list[0]['sessions_count']);

        // History list filters by folder.
        $filtered = $this->actingAs($me, 'sanctum')->getJson("/api/history?folder_id={$folder['id']}")
            ->assertOk()->json('groups');
        $this->assertNotEmpty($filtered);

        // Deleting the folder un-files the session (nullOnDelete), session survives.
        $this->actingAs($me, 'sanctum')->deleteJson("/api/folders/{$folder['id']}")->assertOk();
        $this->assertNull($session->fresh()->folder_id);
        $this->assertNotNull(ChatSession::find($session->id));
    }

    public function test_folders_are_owner_scoped(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser();
        $theirs = Folder::create(['user_id' => $other->id, 'name' => 'Theirs']);

        $this->actingAs($me, 'sanctum')->patchJson("/api/folders/{$theirs->id}", ['name' => 'Hijack'])
            ->assertNotFound();
        $this->assertSame('Theirs', $theirs->fresh()->name);
    }

    public function test_fork_creates_a_branch_session_sharing_lineage(): void
    {
        $me = $this->makeUser();
        $history = app(HistoryService::class);
        $session = $history->startSession($me, 'pastor');
        $history->recordMessage($session, 'user', 'A');
        $history->recordMessage($session, 'assistant', 'B');

        $child = $this->actingAs($me, 'sanctum')
            ->postJson("/api/history/{$session->id}/fork")
            ->assertCreated()->json('session');

        $this->assertSame($session->id, $child['parent_session_id']);
        $this->assertSame($session->fresh()->active_node_id, $child['parent_node_id']);
        // Parent is untouched; child is a separate session.
        $this->assertNotSame($session->id, $child['id']);
        $this->assertSame(2, SessionNode::where('session_id', $session->id)->count());
    }

    public function test_fork_requires_a_node_to_branch_from(): void
    {
        $me = $this->makeUser();
        $empty = app(HistoryService::class)->startSession($me, 'pastor');

        $this->actingAs($me, 'sanctum')->postJson("/api/history/{$empty->id}/fork")
            ->assertStatus(422);
    }

    public function test_message_body_search_matches_node_content_only_when_scope_all(): void
    {
        $me = $this->makeUser();
        $history = app(HistoryService::class);
        $session = $history->startSession($me, 'pastor', ['title' => 'Untitled']);
        $history->recordMessage($session, 'user', 'Please pray about my anxiety at work');

        // Default scope (meta) searches title/summary only — no match.
        $this->actingAs($me, 'sanctum')->postJson('/api/history/search', ['q' => 'anxiety'])
            ->assertOk()->assertJsonCount(0, 'results');

        // scope=all matches inside the (encrypted) message body.
        $hits = $this->actingAs($me, 'sanctum')
            ->postJson('/api/history/search', ['q' => 'anxiety', 'scope' => 'all'])
            ->assertOk()->json('results');
        $this->assertCount(1, $hits);
        $this->assertSame($session->id, $hits[0]['id']);
    }

    public function test_message_body_search_stays_owner_scoped(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser();
        $theirs = app(HistoryService::class)->startSession($other, 'pastor');
        app(HistoryService::class)->recordMessage($theirs, 'user', 'secret confession text');

        $this->actingAs($me, 'sanctum')
            ->postJson('/api/history/search', ['q' => 'confession', 'scope' => 'all'])
            ->assertOk()->assertJsonCount(0, 'results');
    }

    public function test_church_analytics_requires_staff_and_returns_aggregates(): void
    {
        $member = $this->makeUser();
        $this->actingAs($member, 'sanctum')->getJson('/api/admin/analytics')->assertForbidden();

        $admin = $this->makeUser(['is_admin' => true]);
        app(HistoryService::class)->startSession($admin, 'pastor');

        $body = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/analytics')
            ->assertOk()->json();
        $this->assertArrayHasKey('users', $body);
        $this->assertArrayHasKey('sessions', $body);
        $this->assertGreaterThanOrEqual(1, $body['sessions']['total']);
    }
}
