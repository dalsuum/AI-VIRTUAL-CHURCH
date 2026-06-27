<?php

namespace App\Domains\Bible\Services;

use App\Domains\Bible\Events\ReadingReminderSent;
use App\Domains\Bible\Models\ReminderSetting;
use App\Domains\Bible\Notifications\DailyReadingReminderNotification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Resolves due daily-reading reminders and delivers them. Timezone-correct: each slot
 * is evaluated in the user's own timezone. Idempotent: a reminder for a (user, slot,
 * local-date) is sent at most once, so a five-minute scheduler tick (or an overlapping
 * run) never double-sends. Only sends to users with an active plan — the reminder's job
 * is to open today's reading. Emits ReadingReminderSent AFTER delivery, as a record.
 */
class ReminderService
{
    /** How wide the "just became due" window is — matches the scheduler cadence. */
    private const WINDOW_MINUTES = 5;

    public function __construct(private readonly ReadingPlanService $plans)
    {
    }

    /** Send every reminder that came due in the last window. Returns the count sent. */
    public function dispatchDue(?CarbonImmutable $now = null): int
    {
        $nowUtc = $now ?? CarbonImmutable::now();
        $sent = 0;

        ReminderSetting::with('user')->where('enabled', true)->chunkById(200, function ($settings) use ($nowUtc, &$sent) {
            foreach ($settings as $setting) {
                $sent += $this->dispatchForUser($setting, $nowUtc);
            }
        });

        return $sent;
    }

    private function dispatchForUser(ReminderSetting $setting, CarbonImmutable $nowUtc): int
    {
        $user = $setting->user;
        if (! $user) {
            return 0;
        }

        $tz       = $setting->timezone ?: ($user->timezone ?: 'UTC');
        $localNow = $nowUtc->setTimezone($tz);
        $today    = $this->plans->today($user);
        if (! $today || ! ($today['day'] ?? null)) {
            return 0; // no active plan / already finished — nothing to remind about
        }

        $sent = 0;
        foreach (ReminderSetting::SLOTS as $slot) {
            $time = $setting->slotTime($slot);          // "HH:MM" or null
            if (! $time || ! $this->isDue($localNow, $time)) {
                continue;
            }
            $date = $localNow->toDateString();
            if ($this->alreadySent($user, $slot, $date)) {
                continue;                                // idempotent
            }

            $user->notify(new DailyReadingReminderNotification($slot, $date, $today, (string) Str::uuid()));
            ReadingReminderSent::dispatch($user->id, $slot);
            $sent++;
        }

        return $sent;
    }

    /** True when the slot's local time falls within the just-elapsed window. */
    private function isDue(CarbonImmutable $localNow, string $hhmm): bool
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        $slotMoment = $localNow->setTime($h, $m, 0);

        return $slotMoment->lessThanOrEqualTo($localNow)
            && $slotMoment->greaterThan($localNow->subMinutes(self::WINDOW_MINUTES));
    }

    private function alreadySent(User $user, string $slot, string $date): bool
    {
        return $user->notifications()
            ->where('type', DailyReadingReminderNotification::class)
            ->where('data->slot', $slot)
            ->where('data->date', $date)
            ->exists();
    }
}
