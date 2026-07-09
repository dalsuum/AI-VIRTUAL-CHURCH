<?php

namespace App\Http\Controllers;

use App\Domains\Bible\Models\ReadingPlan;
use App\Domains\Bible\Models\ReadingSession;
use App\Domains\Bible\Services\ReadingPlanService;
use App\Domains\Bible\Services\ReadingSessionService;
use App\Domains\Groups\Models\Group;
use Illuminate\Http\Request;

/**
 * Thin orchestration over ReadingPlanService (sole mutator of reading progress) and
 * ReadingSessionService (sole mutator of shared sessions). The controller never
 * advances progress, writes session state or emits events itself.
 */
class BibleReadingController extends Controller
{
    public function __construct(
        private readonly ReadingPlanService $plans,
        private readonly ReadingSessionService $sessions,
    ) {
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

    // ── Shared reading sessions (v1.3 Phase D) ─────────────────────────────────

    /** Create a shared session for a group (group leader / church elder+). */
    public function createSession(Request $request, Group $group)
    {
        $this->authorize('manage', $group);

        $data = $request->validate([
            'reading_plan_id' => ['required', 'integer', 'exists:reading_plans,id'],
        ]);

        $session = $this->sessions->createForGroup(
            $request->user(), $group, ReadingPlan::findOrFail($data['reading_plan_id']),
        );

        return response()->json($this->presentSession($session->load(['plan', 'group'])), 201);
    }

    /** A group's sessions, newest first (anyone who can view the group). */
    public function sessions(Request $request, Group $group)
    {
        $this->authorize('view', $group);

        return response()->json(
            $group->readingSessions()->with(['plan', 'group'])->withCount('participants')
                ->latest()->get()
                ->map(fn ($s) => $this->presentSession($s))->values(),
        );
    }

    /** Session detail: the shared roster, each member reading at their own pace. */
    public function session(Request $request, ReadingSession $session)
    {
        $this->authorize('view', $session->group);

        $session->load(['plan', 'group', 'participants.user', 'participants.enrollment']);

        return response()->json($this->presentSession($session, roster: true));
    }

    /** Join a session (active group members). Idempotent per member. */
    public function joinSession(Request $request, ReadingSession $session)
    {
        $participant = $this->sessions->join($request->user(), $session);

        return response()->json([
            'session_id'       => $session->id,
            'plan_id'          => $participant->enrollment?->reading_plan_id,
            'current_sequence' => $participant->enrollment?->current_sequence,
            'joined_at'        => optional($participant->joined_at)->toIso8601String(),
        ]);
    }

    public function startSession(Request $request, ReadingSession $session)
    {
        return response()->json($this->presentSession(
            $this->sessions->start($request->user(), $session)->load(['plan', 'group']),
        ));
    }

    public function pauseSession(Request $request, ReadingSession $session)
    {
        return response()->json($this->presentSession(
            $this->sessions->pause($request->user(), $session)->load(['plan', 'group']),
        ));
    }

    public function resumeSession(Request $request, ReadingSession $session)
    {
        return response()->json($this->presentSession(
            $this->sessions->resume($request->user(), $session)->load(['plan', 'group']),
        ));
    }

    public function completeSession(Request $request, ReadingSession $session)
    {
        return response()->json($this->presentSession(
            $this->sessions->complete($request->user(), $session)->load(['plan', 'group']),
        ));
    }

    public function abandonSession(Request $request, ReadingSession $session)
    {
        return response()->json($this->presentSession(
            $this->sessions->abandon($request->user(), $session)->load(['plan', 'group']),
        ));
    }

    /** Stable API projection of a session; the roster surfaces each participant's
     *  own enrollment progress — the session itself owns none. */
    private function presentSession(ReadingSession $s, bool $roster = false): array
    {
        $base = [
            'id'           => $s->id,
            'status'       => $s->status,
            'group'        => $s->relationLoaded('group') && $s->group
                ? ['id' => $s->group->id, 'name' => $s->group->name] : ['id' => $s->group_id],
            'plan'         => $s->relationLoaded('plan') && $s->plan
                ? ['id' => $s->plan->id, 'title' => $s->plan->title, 'day_count' => $s->plan->day_count]
                : ['id' => $s->reading_plan_id],
            'created_by'   => $s->created_by,
            'started_at'   => optional($s->started_at)->toIso8601String(),
            'completed_at' => optional($s->completed_at)->toIso8601String(),
            'participant_count' => $s->participants_count
                ?? ($s->relationLoaded('participants') ? $s->participants->count() : $s->participants()->count()),
        ];

        if ($roster) {
            $base['participants'] = $s->participants->map(fn ($p) => [
                'user'             => ['id' => $p->user?->id, 'name' => $p->user?->name],
                'current_sequence' => $p->enrollment?->current_sequence,
                'status'           => $p->enrollment?->status,
                'last_read_on'     => optional($p->enrollment?->last_read_on)->toDateString(),
                'joined_at'        => optional($p->joined_at)->toIso8601String(),
            ])->values();
        }

        return $base;
    }
}
