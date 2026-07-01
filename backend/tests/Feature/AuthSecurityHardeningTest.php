<?php

namespace Tests\Feature;

use App\Models\ServiceSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthSecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_flow_never_claims_a_real_email(): void
    {
        $this->postJson('/api/guest', [
            'name'  => 'Walk Up',
            'email' => 'claim-me@example.com',
        ])
            ->assertCreated()
            ->assertJsonPath('user.email', null)
            ->assertJsonPath('user.is_guest', true);

        $user = User::first();
        $this->assertNotNull($user);
        $this->assertStringEndsWith('@guest.local', $user->email);
        $this->assertSame(User::ROLE_GUEST, $user->role());
        $this->assertDatabaseMissing('users', ['email' => 'claim-me@example.com']);
    }

    public function test_guest_email_update_is_contact_only_and_does_not_claim_identity(): void
    {
        $guest = $this->makeUser([
            'email' => 'walkup@guest.local',
            'role'  => User::ROLE_GUEST,
        ]);

        $this->actingAs($guest)
            ->patchJson('/api/me/email', ['email' => 'real-person@example.com'])
            ->assertOk()
            ->assertJsonPath('contact_email', 'real-person@example.com')
            ->assertJsonPath('user.is_guest', true);

        $this->assertSame('walkup@guest.local', $guest->fresh()->email);
        $this->assertDatabaseMissing('users', ['email' => 'real-person@example.com']);
    }

    public function test_unauthenticated_non_json_request_gets_401_not_login_redirect(): void
    {
        // SSE clients (EventSource) send Accept: text/event-stream, not JSON. The
        // framework's default redirects unauthenticated non-JSON requests to the
        // `login` route, which doesn't exist here → 500, re-triggering the client's
        // reconnect loop. It must be a clean 401 instead.
        $this->assertGuest();

        $this->get('/api/me', ['Accept' => 'text/event-stream'])
            ->assertStatus(401);
    }

    public function test_service_resume_token_is_scoped_and_does_not_log_in_owner(): void
    {
        $user = $this->makeUser();
        $session = $this->serviceFor($user);
        $resumeToken = $session->issueResumeToken();

        $this->getJson("/api/service/{$resumeToken}/resume")
            ->assertOk()
            ->assertJsonPath('session_token', $session->session_token);

        $this->assertGuest();
        $this->getJson('/api/me')->assertStatus(401);

        $this->getJson("/api/service/{$session->session_token}")
            ->assertOk()
            ->assertJsonPath('status', 'active');

        $this->getJson("/api/service/{$resumeToken}/resume")->assertStatus(404);
    }

    public function test_blocked_owner_cannot_use_service_resume_token(): void
    {
        $user = $this->makeUser(['is_blocked' => true]);
        $session = $this->serviceFor($user);
        $resumeToken = $session->issueResumeToken();

        $this->getJson("/api/service/{$resumeToken}/resume")->assertStatus(403);
        $this->getJson("/api/service/{$session->session_token}")->assertStatus(403);
    }

    public function test_blocked_authenticated_user_is_rejected_after_login(): void
    {
        $user = $this->makeUser(['is_blocked' => true]);

        $this->actingAs($user)->getJson('/api/me')
            ->assertStatus(403)
            ->assertJson(['message' => 'This account has been suspended.']);
    }

    public function test_password_reset_rotates_session_version_and_rejects_old_cookie_sessions(): void
    {
        $user = $this->makeUser();
        $rawToken = Str::random(64);
        $user->update([
            'password_reset_token'      => hash('sha256', $rawToken),
            'password_reset_expires_at' => Carbon::now()->addHour(),
        ]);

        $this->postJson('/api/reset-password', [
            'token'        => $rawToken,
            'new_password' => 'Sup3r-New-Pw!',
        ])->assertOk();

        $this->assertSame(1, (int) $user->fresh()->auth_session_version);

        $this->withSession([User::AUTH_SESSION_VERSION_KEY => 0])
            ->actingAs($user->fresh())
            ->getJson('/api/me')
            ->assertStatus(401)
            ->assertJson(['message' => 'Your session has expired. Please sign in again.']);
    }

    public function test_set_admin_false_updates_the_role_that_gates_access(): void
    {
        $actor = $this->makeUser(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $target = $this->makeUser(['role' => User::ROLE_ADMIN, 'is_admin' => true]);

        $this->actingAs($actor)
            ->patchJson("/api/admin/users/{$target->id}/admin", ['is_admin' => false])
            ->assertOk()
            ->assertJsonPath('role', User::ROLE_MEMBER);

        $this->assertSame(User::ROLE_MEMBER, $target->fresh()->role());
        $this->assertFalse((bool) $target->fresh()->is_admin);
    }

    private function serviceFor(User $user): ServiceSession
    {
        return ServiceSession::create([
            'user_id'          => $user->id,
            'session_token'    => Str::random(64),
            'status'           => 'active',
            'music_source'     => 'hymn_sung',
            'presenter_gender' => 'female',
        ]);
    }
}
