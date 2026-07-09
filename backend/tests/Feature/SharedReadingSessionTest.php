<?php

namespace Tests\Feature;

use App\Domains\Bible\Events\ReadingSessionStarted;
use App\Domains\Bible\Models\ReadingPlan;
use App\Domains\Bible\Models\ReadingPlanDay;
use App\Domains\Bible\Models\ReadingParticipant;
use App\Domains\Bible\Services\ReadingPlanService;
use App\Domains\Church\Models\Church;
use App\Domains\Church\Models\ChurchMembership;
use App\Domains\Groups\Models\Group;
use App\Domains\Groups\Models\GroupMembership;
use App\Enums\ChurchRole;
use App\Enums\GroupRole;
use App\Enums\GroupType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SharedReadingSessionTest extends TestCase
{
    use RefreshDatabase;

    private Church $church;
    private Group $group;
    private User $leader;
    private ReadingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->church = Church::create(['name' => 'Grace', 'slug' => 'grace']);
        $this->group  = Group::create(['church_id' => $this->church->id, 'name' => 'Bible Study', 'type' => GroupType::BIBLE_STUDY]);
        $this->leader = $this->makeUser();
        $this->churchMember($this->leader, ChurchRole::MEMBER);
        $this->groupMember($this->leader, $this->group, GroupRole::LEADER);
        $this->plan = $this->makePlan('gospel-sprint', 2);
    }

    private function churchMember(User $u, ChurchRole $role): void
    {
        ChurchMembership::create([
            'church_id' => $this->church->id, 'user_id' => $u->id, 'role' => $role,
            'status' => ChurchMembership::STATUS_ACTIVE, 'joined_at' => now(),
        ]);
    }

    private function groupMember(User $u, Group $g, GroupRole $role): void
    {
        GroupMembership::create([
            'group_id' => $g->id, 'user_id' => $u->id, 'role' => $role,
            'status' => GroupMembership::STATUS_ACTIVE, 'joined_at' => now(),
        ]);
    }

    private function makePlan(string $slug, int $days): ReadingPlan
    {
        $plan = ReadingPlan::create([
            'slug' => $slug, 'title' => ucfirst($slug), 'language' => 'en', 'day_count' => $days,
        ]);
        foreach (range(1, $days) as $i) {
            ReadingPlanDay::create([
                'reading_plan_id' => $plan->id, 'sequence' => $i,
                'slug' => sprintf('day-%03d', $i), 'title' => "Day {$i}",
                'passages' => [['book' => 'john', 'chapter' => $i]],
            ]);
        }

        return $plan;
    }

    /** Leader creates a session for the fixture group; returns its id. */
    private function createSession(): string
    {
        return $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/groups/{$this->group->id}/reading-sessions", ['reading_plan_id' => $this->plan->id])
            ->assertCreated()->json('id');
    }

    /** A church+group member ready to join sessions. */
    private function makeJoiner(): User
    {
        $u = $this->makeUser();
        $this->churchMember($u, ChurchRole::MEMBER);
        $this->groupMember($u, $this->group, GroupRole::MEMBER);

        return $u;
    }

    public function test_leader_creates_session_and_plain_member_cannot(): void
    {
        $res = $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/groups/{$this->group->id}/reading-sessions", ['reading_plan_id' => $this->plan->id])
            ->assertCreated()->json();

        $this->assertSame('planned', $res['status']);
        $this->assertSame('Bible Study', $res['group']['name']);
        $this->assertSame($this->plan->id, $res['plan']['id']);

        $member = $this->makeJoiner();
        $this->actingAs($member, 'sanctum')
            ->postJson("/api/groups/{$this->group->id}/reading-sessions", ['reading_plan_id' => $this->plan->id])
            ->assertForbidden();
    }

    public function test_church_elder_creates_without_group_membership(): void
    {
        $elder = $this->makeUser();
        $this->churchMember($elder, ChurchRole::ELDER);
        $other = Group::create(['church_id' => $this->church->id, 'name' => 'Choir', 'type' => GroupType::CHOIR]);

        $this->actingAs($elder, 'sanctum')
            ->postJson("/api/groups/{$other->id}/reading-sessions", ['reading_plan_id' => $this->plan->id])
            ->assertCreated();
    }

    public function test_one_open_session_per_group(): void
    {
        $id = $this->createSession();

        $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/groups/{$this->group->id}/reading-sessions", ['reading_plan_id' => $this->plan->id])
            ->assertStatus(409);

        // Once the open session reaches a terminal state, a new one may begin.
        $this->actingAs($this->leader, 'sanctum')->postJson("/api/reading-sessions/{$id}/abandon")->assertOk();
        $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/groups/{$this->group->id}/reading-sessions", ['reading_plan_id' => $this->plan->id])
            ->assertCreated();
    }

    public function test_member_joins_and_reads_through_their_own_enrollment(): void
    {
        $id     = $this->createSession();
        $joiner = $this->makeJoiner();

        $this->actingAs($joiner, 'sanctum')->postJson("/api/reading-sessions/{$id}/join")
            ->assertOk()->assertJsonFragment(['plan_id' => $this->plan->id, 'current_sequence' => 1]);

        // The participation references a REAL individual enrollment — no second model.
        $this->assertDatabaseHas('user_reading_plans', [
            'user_id' => $joiner->id, 'reading_plan_id' => $this->plan->id, 'status' => 'active',
        ]);

        // Idempotent: rejoining creates nothing new.
        $this->actingAs($joiner, 'sanctum')->postJson("/api/reading-sessions/{$id}/join")->assertOk();
        $this->assertSame(1, ReadingParticipant::where('user_id', $joiner->id)->count());
        $this->assertSame(1, $joiner->readingPlans()->count());
    }

    public function test_non_group_member_cannot_join(): void
    {
        $id       = $this->createSession();
        $churchOnly = $this->makeUser();
        $this->churchMember($churchOnly, ChurchRole::MEMBER);

        $this->actingAs($churchOnly, 'sanctum')->postJson("/api/reading-sessions/{$id}/join")->assertForbidden();
    }

    public function test_join_with_a_different_active_plan_conflicts(): void
    {
        $id     = $this->createSession();
        $joiner = $this->makeJoiner();
        app(ReadingPlanService::class)->enroll($joiner, $this->makePlan('psalms-month', 3));

        // ReadingPlanService's one-active-plan rule applies verbatim inside sessions.
        $this->actingAs($joiner, 'sanctum')->postJson("/api/reading-sessions/{$id}/join")->assertStatus(409);
    }

    public function test_start_goes_live_and_emits_session_started(): void
    {
        $id = $this->createSession();

        // A plain member cannot steer the session.
        $member = $this->makeJoiner();
        $this->actingAs($member, 'sanctum')->postJson("/api/reading-sessions/{$id}/start")->assertForbidden();

        Event::fake([ReadingSessionStarted::class]);
        $res = $this->actingAs($this->leader, 'sanctum')->postJson("/api/reading-sessions/{$id}/start")
            ->assertOk()->json();

        $this->assertSame('active', $res['status']);
        $this->assertNotNull($res['started_at']);
        Event::assertDispatched(ReadingSessionStarted::class, fn ($e) => $e->sessionId === $id
            && $e->groupId === $this->group->id && $e->userId === $this->leader->id);
    }

    public function test_lifecycle_legality_is_owned_by_the_service(): void
    {
        $id = $this->createSession();
        $as = fn () => $this->actingAs($this->leader, 'sanctum');

        $as()->postJson("/api/reading-sessions/{$id}/pause")->assertStatus(409);   // planned → paused: illegal
        $as()->postJson("/api/reading-sessions/{$id}/start")->assertOk();
        $as()->postJson("/api/reading-sessions/{$id}/pause")->assertOk();
        $as()->postJson("/api/reading-sessions/{$id}/resume")->assertOk();
        $as()->postJson("/api/reading-sessions/{$id}/complete")->assertOk();
        $as()->postJson("/api/reading-sessions/{$id}/complete")->assertOk();       // idempotent no-op
        $as()->postJson("/api/reading-sessions/{$id}/start")->assertStatus(409);   // terminal
    }

    public function test_roster_shows_each_members_own_progress(): void
    {
        $id     = $this->createSession();
        $joiner = $this->makeJoiner();
        $this->actingAs($joiner, 'sanctum')->postJson("/api/reading-sessions/{$id}/join")->assertOk();
        $this->actingAs($this->leader, 'sanctum')->postJson("/api/reading-sessions/{$id}/start")->assertOk();

        // The joiner completes today through the EXISTING individual endpoint…
        $this->actingAs($joiner, 'sanctum')->postJson('/api/bible/reading/today/complete')->assertOk();

        // …and the shared roster reflects it, because their enrollment IS their progress.
        $roster = $this->actingAs($this->leader, 'sanctum')->getJson("/api/reading-sessions/{$id}")
            ->assertOk()->json('participants');

        $mine = collect($roster)->firstWhere('user.id', $joiner->id);
        $this->assertSame(2, $mine['current_sequence']);
        $this->assertNotNull($mine['last_read_on']);
    }

    public function test_outsiders_cannot_view_sessions_but_members_can_list(): void
    {
        $id = $this->createSession();

        $outsider = $this->makeUser();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/reading-sessions/{$id}")->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson("/api/groups/{$this->group->id}/reading-sessions")->assertForbidden();

        $member = $this->makeJoiner();
        $this->actingAs($member, 'sanctum')->getJson("/api/groups/{$this->group->id}/reading-sessions")
            ->assertOk()->assertJsonCount(1);
    }
}
