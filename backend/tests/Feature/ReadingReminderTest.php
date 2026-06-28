<?php

namespace Tests\Feature;

use App\Domains\Bible\Models\ReadingPlan;
use App\Domains\Bible\Models\ReadingPlanDay;
use App\Domains\Bible\Models\ReminderSetting;
use App\Domains\Bible\Models\UserReadingPlan;
use App\Domains\Bible\Notifications\DailyReadingReminderNotification;
use App\Domains\Bible\Services\ReminderService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadingReminderTest extends TestCase
{
    use RefreshDatabase;

    private function enrolledUser(string $tz, array $reminder): User
    {
        $u = $this->makeUser(['timezone' => $tz]);
        $plan = ReadingPlan::create(['slug' => 'p', 'title' => 'P', 'day_count' => 3]);
        ReadingPlanDay::create([
            'reading_plan_id' => $plan->id, 'sequence' => 1, 'slug' => 'day-001',
            'title' => 'Day 1', 'passages' => [['book' => 'Genesis', 'chapter' => 1]],
        ]);
        UserReadingPlan::create([
            'user_id' => $u->id, 'reading_plan_id' => $plan->id, 'status' => 'active',
            'current_sequence' => 1, 'started_on' => now(),
        ]);
        ReminderSetting::create(array_merge(['user_id' => $u->id, 'enabled' => true, 'timezone' => $tz], $reminder));

        return $u;
    }

    public function test_reminder_sent_once_in_window_and_is_idempotent(): void
    {
        $u = $this->enrolledUser('UTC', ['morning_at' => '07:00', 'channels' => ['in_app']]);
        $now = CarbonImmutable::parse('2026-07-01 07:00:00', 'UTC');

        $sent = app(ReminderService::class)->dispatchDue($now);
        $this->assertSame(1, $sent);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $u->id, 'type' => DailyReadingReminderNotification::class,
            'data->slot' => 'morning', 'data->date' => '2026-07-01',
        ]);

        // Same window again → no duplicate.
        $this->assertSame(0, app(ReminderService::class)->dispatchDue($now));
        $this->assertSame(1, $u->fresh()->notifications()
            ->where('type', DailyReadingReminderNotification::class)->count());
    }

    public function test_timezone_is_respected(): void
    {
        // 07:00 local in Yangon (UTC+6:30) == 00:30 UTC.
        $u = $this->enrolledUser('Asia/Yangon', ['morning_at' => '07:00', 'channels' => ['in_app']]);
        $due = CarbonImmutable::parse('2026-07-01 00:30:00', 'UTC');
        $notDue = CarbonImmutable::parse('2026-07-01 12:00:00', 'UTC');

        $this->assertSame(0, app(ReminderService::class)->dispatchDue($notDue));
        $this->assertSame(1, app(ReminderService::class)->dispatchDue($due));
    }

    public function test_disabled_or_unenrolled_users_get_nothing(): void
    {
        $u = $this->enrolledUser('UTC', ['morning_at' => '07:00', 'enabled' => false]);
        $now = CarbonImmutable::parse('2026-07-01 07:00:00', 'UTC');
        $this->assertSame(0, app(ReminderService::class)->dispatchDue($now));
    }

    public function test_via_channels_follow_user_settings(): void
    {
        $u = $this->makeUser();
        $note = new DailyReadingReminderNotification('morning', '2026-07-01', ['plan' => ['id' => 1], 'day' => ['sequence' => 1]]);

        // in_app only → database channel only.
        $u->setRelation('reminderSetting', new ReminderSetting(['channels' => ['in_app']]));
        $this->assertSame([\App\Domains\Notifications\Channels\CommunityDatabaseChannel::class], $note->via($u));

        // in_app + email (user has email) → both.
        $u->setRelation('reminderSetting', new ReminderSetting(['channels' => ['in_app', 'email']]));
        $this->assertSame(
            [\App\Domains\Notifications\Channels\CommunityDatabaseChannel::class, 'mail'],
            $note->via($u),
        );
    }
}
