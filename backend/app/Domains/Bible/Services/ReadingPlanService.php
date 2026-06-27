<?php

namespace App\Domains\Bible\Services;

use App\Domains\Bible\Events\ReadingDayCompleted;
use App\Domains\Bible\Events\ReadingPlanCompleted;
use App\Domains\Bible\Exceptions\ReadingException;
use App\Domains\Bible\Models\ReadingPlan;
use App\Domains\Bible\Models\ReadingPlanDay;
use App\Domains\Bible\Models\UserReadingPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONLY mutator of reading enrollment + progress. Enrollment never fabricates
 * completion events — only completeToday() (a user action) advances progress and emits
 * ReadingDayCompleted / ReadingPlanCompleted. Progress is anchored to the plan, not the
 * calendar: missing real days never skips content.
 */
class ReadingPlanService
{
    /** Enroll the user in a plan. Idempotent for the same plan; one active plan at a time. */
    public function enroll(User $user, ReadingPlan $plan): UserReadingPlan
    {
        return DB::transaction(function () use ($user, $plan) {
            $active = $this->activeFor($user);
            if ($active) {
                if ($active->reading_plan_id === $plan->id) {
                    return $active; // idempotent re-enroll
                }
                throw ReadingException::conflict('You already have an active reading plan. Finish or leave it first.');
            }

            return UserReadingPlan::create([
                'user_id'          => $user->id,
                'reading_plan_id'  => $plan->id,
                'status'           => UserReadingPlan::STATUS_ACTIVE,
                'current_sequence' => 1,
                'started_on'       => $this->localDate($user),
            ]);
        });
    }

    /** The user's active enrollment (with plan), or null. */
    public function activeFor(User $user): ?UserReadingPlan
    {
        return UserReadingPlan::with('plan')
            ->where('user_id', $user->id)
            ->where('status', UserReadingPlan::STATUS_ACTIVE)
            ->first();
    }

    /** Today's reading: the current day's references + progress, or null if not enrolled. */
    public function today(User $user): ?array
    {
        $enrollment = $this->activeFor($user);
        if (! $enrollment) {
            return null;
        }

        $day = $enrollment->plan?->dayAt($enrollment->current_sequence);

        return [
            'plan'        => ['id' => $enrollment->reading_plan_id, 'title' => $enrollment->plan?->title],
            'day'         => $day ? [
                'sequence' => $day->sequence,
                'slug'     => $day->slug,
                'title'    => $day->title,
                'passages' => $day->passages,
            ] : null,
            'progress'    => [
                'current_sequence' => $enrollment->current_sequence,
                'day_count'        => $enrollment->plan?->day_count,
            ],
            'completed'   => $enrollment->status === UserReadingPlan::STATUS_COMPLETED,
            'read_today'  => optional($enrollment->last_read_on)->toDateString() === $this->localDate($user),
        ];
    }

    /**
     * Mark the current day complete: advance progress, stamp the local date, and emit
     * ReadingDayCompleted (+ ReadingPlanCompleted on the final day). Idempotent per local
     * day — a second call the same day is a no-op, so it can't double-advance.
     */
    public function completeToday(User $user): UserReadingPlan
    {
        return DB::transaction(function () use ($user) {
            $enrollment = UserReadingPlan::with('plan')
                ->where('user_id', $user->id)
                ->where('status', UserReadingPlan::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if (! $enrollment) {
                throw ReadingException::conflict('You have no active reading plan.');
            }

            $localDate = $this->localDate($user);
            if (optional($enrollment->last_read_on)->toDateString() === $localDate) {
                return $enrollment; // already read today — idempotent no-op
            }

            $plan        = $enrollment->plan;
            $completedSeq = $enrollment->current_sequence;
            $day          = $plan?->dayAt($completedSeq);
            $isLastDay    = $completedSeq >= (int) $plan?->day_count;
            $correlationId = (string) Str::uuid();

            $enrollment->forceFill([
                'last_read_on'     => $localDate,
                'current_sequence' => $isLastDay ? $completedSeq : $completedSeq + 1,
                'status'           => $isLastDay ? UserReadingPlan::STATUS_COMPLETED : UserReadingPlan::STATUS_ACTIVE,
                'completed_at'     => $isLastDay ? now() : null,
            ])->save();

            ReadingDayCompleted::dispatch(
                $user->id, $localDate, (int) $plan?->id, $completedSeq,
                (string) ($day?->slug ?? 'day-'.$completedSeq), $correlationId,
            );

            if ($isLastDay) {
                ReadingPlanCompleted::dispatch($user->id, (int) $plan?->id, $correlationId);
            }

            return $enrollment;
        });
    }

    /** The user's current local date (Y-m-d), preferring reminder tz, then profile tz. */
    private function localDate(User $user): string
    {
        $tz = $user->reminderSetting?->timezone ?: ($user->timezone ?: 'UTC');

        return now()->setTimezone($tz)->toDateString();
    }
}
