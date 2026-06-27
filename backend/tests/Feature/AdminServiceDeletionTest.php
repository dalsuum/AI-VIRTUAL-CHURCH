<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\ServiceSession;
use App\Models\ServiceSessionMeta;
use App\Models\User;
use App\Services\HistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminServiceDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return $this->makeUser(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
    }

    /** A worshipper's service + its linked profile-history entry, sharing one chat_session id. */
    private function makeService(User $user): array
    {
        $chat = app(HistoryService::class)->startSession($user, 'service');
        $svc = ServiceSession::create([
            'user_id' => $user->id, 'session_token' => 'tok_'.uniqid(), 'status' => 'active', 'language' => 'en',
        ]);
        ServiceSessionMeta::create(['chat_session_id' => $chat->id, 'service_session_id' => $svc->id]);

        return [$chat->id, $svc->id];
    }

    public function test_deleting_a_service_also_removes_the_users_profile_history_entry(): void
    {
        $user = $this->makeUser();
        [$chatId, $svcId] = $this->makeService($user);

        $this->actingAs($this->admin())
            ->deleteJson("/api/admin/services/{$svcId}")
            ->assertOk();

        $this->assertDatabaseMissing('service_sessions', ['id' => $svcId]);
        // The spine row must be gone entirely (force-deleted), not left orphaned/soft-deleted.
        $this->assertNull(ChatSession::withTrashed()->find($chatId));
        $this->assertDatabaseMissing('service_sessions_meta', ['chat_session_id' => $chatId]);
    }

    public function test_bulk_delete_services_cascades_each_history_entry(): void
    {
        $user = $this->makeUser();
        [$chatA, $svcA] = $this->makeService($user);
        [$chatB, $svcB] = $this->makeService($user);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/services/bulk-delete', ['service_ids' => [$svcA, $svcB]])
            ->assertOk()
            ->assertJson(['deleted' => 2]);

        $this->assertNull(ChatSession::withTrashed()->find($chatA));
        $this->assertNull(ChatSession::withTrashed()->find($chatB));
    }
}
