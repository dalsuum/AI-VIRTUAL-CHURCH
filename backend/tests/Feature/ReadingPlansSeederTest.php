<?php

namespace Tests\Feature;

use App\Domains\Bible\Models\ReadingPlan;
use App\Domains\Bible\Models\ReadingPlanDay;
use Database\Seeders\ReadingPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadingPlansSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_bible_in_a_year_with_full_canon_and_is_idempotent(): void
    {
        $this->seed(ReadingPlansSeeder::class);

        $plan = ReadingPlan::where('slug', 'bible-in-a-year')->firstOrFail();
        $this->assertSame(365, $plan->day_count);
        $this->assertSame(365, $plan->days()->count());

        // All 1,189 chapters of the Protestant canon are covered exactly once —
        // no gaps, no duplicates (the seeder self-validates this before inserting).
        $all = ReadingPlanDay::where('reading_plan_id', $plan->id)->get()
            ->flatMap(fn (ReadingPlanDay $d) => array_map(fn ($p) => $p['book'].'|'.$p['chapter'], $d->passages));
        $this->assertSame(1189, $all->count(), 'total chapters');
        $this->assertSame(1189, $all->unique()->count(), 'distinct chapters (no duplicates)');

        // Stable per-day slug.
        $this->assertDatabaseHas('reading_plan_days', ['reading_plan_id' => $plan->id, 'slug' => 'day-001']);

        // Re-running inserts nothing.
        $this->seed(ReadingPlansSeeder::class);
        $this->assertSame(1, ReadingPlan::where('slug', 'bible-in-a-year')->count());
        $this->assertSame(365, $plan->days()->count());
    }
}
