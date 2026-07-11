<?php

namespace Tests\Feature;

use App\Domains\Invitations\Events\InvitationSent;
use App\Domains\Invitations\Listeners\SendInvitationNotification;
use App\Domains\Invitations\Models\Invitation;
use App\Domains\Invitations\Notifications\InvitationReceivedNotification;
use App\Domains\Invitations\Services\InvitationService;
use App\Enums\InvitationActivity;
use App\Enums\InvitationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private function send(int $inviteeId, string $activity = 'prayer'): array
    {
        return ['invitee_id' => $inviteeId, 'activity' => $activity];
    }

    public function test_send_creates_pending_invitation_and_notifies_invitee(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();

        $res = $this->actingAs($a, 'sanctum')->postJson('/api/invitations', $this->send($b->id))
            ->assertCreated()->json();

        $this->assertSame('pending', $res['status']);
        $this->assertDatabaseHas('invitations', [
            'id' => $res['id'], 'inviter_id' => $a->id, 'invitee_id' => $b->id, 'status' => 'pending',
        ]);

        // The invitee got a high-priority in-app notification carrying the correlation id.
        $invitation = Invitation::find($res['id']);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id'  => $b->id,
            'type'           => InvitationReceivedNotification::class,
            'priority'       => 'high',
            'correlation_id' => $invitation->correlation_id,
        ]);
    }

    public function test_only_invitee_can_accept_and_inviter_is_notified(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $id = $this->actingAs($a, 'sanctum')->postJson('/api/invitations', $this->send($b->id))->json('id');

        // Inviter cannot accept their own invitation.
        $this->actingAs($a, 'sanctum')->postJson("/api/invitations/{$id}/accept")->assertForbidden();

        $this->actingAs($b, 'sanctum')->postJson("/api/invitations/{$id}/accept")->assertOk()
            ->assertJsonFragment(['status' => 'accepted']);

        // Inviter receives an in-app response notification (normal priority).
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $a->id,
            'priority'      => 'normal',
        ]);
    }

    public function test_only_inviter_can_cancel(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $id = $this->actingAs($a, 'sanctum')->postJson('/api/invitations', $this->send($b->id))->json('id');

        $this->actingAs($b, 'sanctum')->postJson("/api/invitations/{$id}/cancel")->assertForbidden();
        $this->actingAs($a, 'sanctum')->postJson("/api/invitations/{$id}/cancel")->assertOk()
            ->assertJsonFragment(['status' => 'cancelled']);
    }

    public function test_double_accept_is_idempotent(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $id = $this->actingAs($a, 'sanctum')->postJson('/api/invitations', $this->send($b->id))->json('id');

        $this->actingAs($b, 'sanctum')->postJson("/api/invitations/{$id}/accept")->assertOk();
        $this->actingAs($b, 'sanctum')->postJson("/api/invitations/{$id}/accept")->assertOk(); // no error

        $this->assertSame(InvitationStatus::ACCEPTED, Invitation::find($id)->status);
        // Inviter notified exactly once despite two accepts.
        $this->assertSame(1, $a->fresh()->notifications()
            ->where('data->type', 'invitation_accepted')->count());
    }

    public function test_cannot_respond_to_a_terminal_invitation(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $id = $this->actingAs($a, 'sanctum')->postJson('/api/invitations', $this->send($b->id))->json('id');

        $this->actingAs($b, 'sanctum')->postJson("/api/invitations/{$id}/decline")->assertOk();
        $this->actingAs($b, 'sanctum')->postJson("/api/invitations/{$id}/accept")->assertStatus(409);
    }

    public function test_expiry_command_expires_pending_and_blocks_response(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $inv = Invitation::create([
            'correlation_id' => (string) Str::uuid(),
            'inviter_id' => $a->id, 'invitee_id' => $b->id, 'activity' => InvitationActivity::PRAYER,
            'status' => InvitationStatus::PENDING, 'expires_at' => now()->subDay(),
        ]);

        $this->artisan('invitations:expire')->assertSuccessful();
        $this->assertSame(InvitationStatus::EXPIRED, $inv->fresh()->status);

        // A response to an already-expired invitation is a conflict.
        $this->actingAs($b, 'sanctum')->postJson("/api/invitations/{$inv->id}/accept")->assertStatus(409);
    }

    public function test_block_prevents_sending_an_invitation(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $this->actingAs($a, 'sanctum')->postJson("/api/friends/{$b->id}/block")->assertOk();

        $this->actingAs($a, 'sanctum')->postJson('/api/invitations', $this->send($b->id))->assertForbidden();
    }

    public function test_validation_rejects_self_invite_and_unknown_activity(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();

        $this->actingAs($a, 'sanctum')->postJson('/api/invitations', $this->send($a->id))
            ->assertStatus(422);
        $this->actingAs($a, 'sanctum')->postJson('/api/invitations', $this->send($b->id, 'brunch'))
            ->assertStatus(422);
    }

    public function test_invitation_notification_listener_is_idempotent_on_replay(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $inv = app(InvitationService::class)->send($a, $b, InvitationActivity::PRAYER);

        // The send already delivered once (sync queue). Replaying the event must not
        // create a duplicate in-app notification.
        (new SendInvitationNotification())->handle(new InvitationSent($inv->id, $inv->correlation_id));

        $this->assertSame(1, $b->fresh()->notifications()
            ->where('type', InvitationReceivedNotification::class)->count());
    }

    public function test_couple_worship_admits_only_the_accepted_invitee(): void
    {
        $a = $this->makeUser();   // inviter, owns the service
        $b = $this->makeUser();   // the spouse
        $service = \App\Models\ServiceSession::create([
            'user_id' => $a->id, 'session_token' => str_repeat('b', 64),
            'status' => 'completed', 'language' => 'en', 'music_source' => 'suno',
        ]);

        // You can only attach YOUR OWN service.
        $this->actingAs($b, 'sanctum')->postJson('/api/invitations', [
            'invitee_id' => $a->id, 'activity' => 'worship', 'service_token' => $service->session_token,
        ])->assertNotFound();

        // A invites B to worship at A's service. Pending: token hidden, playback closed.
        $res = $this->actingAs($a, 'sanctum')->postJson('/api/invitations', [
            'invitee_id' => $b->id, 'activity' => 'worship', 'service_token' => $service->session_token,
        ])->assertCreated()->json();
        $this->assertArrayNotHasKey('service_token', $res);
        $this->actingAs($b, 'sanctum')->getJson("/api/service/{$service->session_token}")->assertNotFound();

        // Acceptance reveals the token and opens playback — two people, one service.
        $this->actingAs($b, 'sanctum')->postJson("/api/invitations/{$res['id']}/accept")->assertOk();
        $received = $this->actingAs($b, 'sanctum')->getJson('/api/invitations')->json('received');
        $mine = collect($received)->firstWhere('id', $res['id']);
        $this->assertSame($service->session_token, $mine['service_token']);
        $this->actingAs($b, 'sanctum')->getJson("/api/service/{$service->session_token}")->assertOk();

        // Anyone else stays out.
        $c = $this->makeUser();
        $this->actingAs($c, 'sanctum')->getJson("/api/service/{$service->session_token}")->assertNotFound();
    }
}
