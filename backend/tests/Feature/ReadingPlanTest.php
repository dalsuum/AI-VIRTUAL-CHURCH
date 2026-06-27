<?php

namespace Tests\Feature;

use App\Domains\Bible\Events\ReadingDayCompleted;
use App\Domains\Bible\Events\ReadingPlanCompleted;
use App\Domains\Bible\Models\ReadingPlan;
use App\Domains\Bible\Models\ReadingPlanDay;
use App\Domains\Bible\Models\UserReadingPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ReadingPlanTest extends TestCase
{
    use RefreshDatabase;

    private function makePlan(int $days = 3, string $slug = 'test-plan'): ReadingPlan
    {
        $plan = ReadingPlan::create(['slug' => $slug, 'title' => ucfirst($slug), 'day_count' => $days]);
        for ($i = 1; $i <= $days; $i++) {
            ReadingPlanDay::create([
                'reading_plan_id' => $plan->id, 'sequence' => $i,
                'slug' => sprintf('day-%03d', $i), 'title' => "Day $i",
                'passages' => [['book' => 'Genesis', 'chapter' => $i]],
            ]);
        }

        return $plan;
    }

    public function test_enroll_is_idempotent_and_enforces_single_active_plan(): void
    {
        $u = $this->makeUser();
        $a = $this->makePlan(3, 'plan-a');
        $b = $this->makePlan(3, 'plan-b');

        $this->actingAs($u, 'sanctum')->postJson("/api/bible/plans/{$a->id}/enroll")->assertCreated();
        $this->actingAs($u, 'sanctum')->postJson("/api/bible/plans/{$a->id}/enroll")->assertCreated(); // idempotent
        $this->assertSame(1, UserReadingPlan::where('user_id', $u->id)->count());

        // A second, different active plan is refused.
        $this->actingAs($u, 'sanctum')->postJson("/api/bible/plans/{$b->id}/enroll")->assertStatus(409);
    }

    public function test_today_returns_current_day_or_null(): void
    {
        $u = $this->makeUser();
        $this->actingAs($u, 'sanctum')->getJson('/api/bible/reading/today')
            ->assertOk()->assertJson(['today' => null]);

        $plan = $this->makePlan(3);
        $this->actingAs($u, 'sanctum')->postJson("/api/bible/plans/{$plan->id}/enroll")->assertCreated();

        $res = $this->actingAs($u, 'sanctum')->getJson('/api/bible/reading/today')->assertOk()->json('today');
        $this->assertSame(1, $res['day']['sequence']);
        $this->assertSame('day-001', $res['day']['slug']);
        $this->assertSame([['book' => 'Genesis', 'chapter' => 1]], $res['day']['passages']);
    }

    public function test_complete_advances_and_is_idempotent_per_local_day(): void
    {
        $u = $this->makeUser();
        $plan = $this->makePlan(3);
        $this->actingAs($u, 'sanctum')->postJson("/api/bible/plans/{$plan->id}/enroll")->assertCreated();

        $this->actingAs($u, 'sanctum')->postJson('/api/bible/reading/today/complete')->assertOk()
            ->assertJsonFragment(['current_sequence' => 2]);

        // Second completion the same local day must NOT advance again.
        $this->actingAs($u, 'sanctum')->postJson('/api/bible/reading/today/complete')->assertOk()
            ->assertJsonFragment(['current_sequence' => 2]);

        // Streak counted exactly once.
        $this->assertSame(1, (int) $u->fresh()->readingStreak->current_streak);
    }

    public function test_completing_the_final_day_finishes_the_plan(): void
    {
        Event::fake([ReadingDayCompleted::class, ReadingPlanCompleted::class]);
        $u = $this->makeUser();
        $plan = $this->makePlan(2);
        $this->actingAs($u, 'sanctum')->postJson("/api/bible/plans/{$plan->id}/enroll")->assertCreated();

        $this->actingAs($u, 'sanctum')->postJson('/api/bible/reading/today/complete')->assertOk(); // day 1
        Event::assertDispatched(ReadingDayCompleted::class);

        $this->travel(1)->days(); // a new local day, so completion isn't a no-op
        $this->actingAs($u, 'sanctum')->postJson('/api/bible/reading/today/complete')->assertOk()
            ->assertJsonFragment(['status' => 'completed']); // day 2 = final

        Event::assertDispatched(ReadingPlanCompleted::class);
    }

    public function test_streak_increments_on_consecutive_days_and_resets_on_gap(): void
    {
        $u = $this->makeUser();
        $plan = $this->makePlan(5);
        $this->actingAs($u, 'sanctum')->postJson("/api/bible/plans/{$plan->id}/enroll")->assertCreated();

        $this->actingAs($u, 'sanctum')->postJson('/api/bible/reading/today/complete'); // day 1
        $this->travel(1)->days();
        $this->actingAs($u, 'sanctum')->postJson('/api/bible/reading/today/complete'); // day 2 (consecutive)
        $this->assertSame(2, (int) $u->fresh()->readingStreak->current_streak);

        $this->travel(2)->days(); // skip a day → gap
        $this->actingAs($u, 'sanctum')->postJson('/api/bible/reading/today/complete'); // resets
        $streak = $u->fresh()->readingStreak;
        $this->assertSame(1, (int) $streak->current_streak);
        $this->assertSame(2, (int) $streak->longest_streak); // longest preserved
    }
}
