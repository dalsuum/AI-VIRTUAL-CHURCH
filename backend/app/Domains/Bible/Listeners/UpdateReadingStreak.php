<?php

namespace App\Domains\Bible\Listeners;

use App\Domains\Bible\Events\ReadingDayCompleted;
use App\Domains\Bible\Models\ReadingStreak;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Maintains the durable reading streak from ReadingDayCompleted. The streak counts
 * consecutive LOCAL days (the event carries the user-local date). Idempotent: if the
 * streak was already advanced for that date, a replay is a no-op — so queue retries are
 * safe. Side effect only; it never touches reading state.
 */
class UpdateReadingStreak implements ShouldQueue
{
    public function handle(ReadingDayCompleted $event): void
    {
        DB::transaction(function () use ($event) {
            $streak = ReadingStreak::lockForUpdate()->firstOrNew(['user_id' => $event->userId]);
            $today  = Carbon::parse($event->localDate)->startOfDay();
            $last   = $streak->last_read_on?->startOfDay();

            if ($last && $last->equalTo($today)) {
                return; // already counted for this local date — idempotent
            }

            $streak->current_streak = ($last && $last->equalTo($today->copy()->subDay()))
                ? $streak->current_streak + 1   // consecutive day
                : 1;                            // first ever, or a gap reset it

            $streak->longest_streak = max((int) $streak->longest_streak, $streak->current_streak);
            $streak->last_read_on   = $today->toDateString();
            $streak->save();
        });
    }
}
