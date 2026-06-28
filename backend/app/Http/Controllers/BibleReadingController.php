<?php

namespace App\Http\Controllers;

use App\Domains\Bible\Models\ReadingPlan;
use App\Domains\Bible\Services\ReadingPlanService;
use Illuminate\Http\Request;

/**
 * Thin orchestration over ReadingPlanService — the sole mutator of reading progress.
 * The controller never advances progress or emits events itself.
 */
class BibleReadingController extends Controller
{
    public function __construct(private readonly ReadingPlanService $plans)
    {
    }

    /** Available reading plans. */
    public function plans()
    {
        $plans = ReadingPlan::orderBy('title')->get()
            ->map(fn (ReadingPlan $p) => [
                'id' => $p->id, 'slug' => $p->slug, 'title' => $p->title,
                'description' => $p->description, 'day_count' => $p->day_count,
            ]);

        return response()->json(['plans' => $plans]);
    }

    /** Enroll in a plan (idempotent; one active plan at a time). */
    public function enroll(Request $request, ReadingPlan $plan)
    {
        $enrollment = $this->plans->enroll($request->user(), $plan);

        return response()->json([
            'plan_id'          => $enrollment->reading_plan_id,
            'status'           => $enrollment->status,
            'current_sequence' => $enrollment->current_sequence,
        ], 201);
    }

    /** Today's reading for the active plan (or null when not enrolled). */
    public function today(Request $request)
    {
        return response()->json(['today' => $this->plans->today($request->user())]);
    }

    /** Mark today's reading complete; advances progress and updates the streak. */
    public function complete(Request $request)
    {
        $enrollment = $this->plans->completeToday($request->user());

        return response()->json([
            'status'           => $enrollment->status,
            'current_sequence' => $enrollment->current_sequence,
        ]);
    }

    /** The user's reading streak. */
    public function streak(Request $request)
    {
        $streak = $request->user()->readingStreak;

        return response()->json([
            'current_streak' => (int) ($streak->current_streak ?? 0),
            'longest_streak' => (int) ($streak->longest_streak ?? 0),
            'last_read_on'   => optional($streak?->last_read_on)->toDateString(),
        ]);
    }
}
