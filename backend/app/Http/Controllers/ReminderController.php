<?php

namespace App\Http\Controllers;

use App\Domains\Bible\Models\ReminderSetting;
use App\Http\Requests\UpdateReminderRequest;
use Illuminate\Http\Request;

/**
 * The user's own daily-reading reminder settings. Returns sensible defaults when no row
 * exists yet so the client always sees a complete picture.
 */
class ReminderController extends Controller
{
    public function show(Request $request)
    {
        return response()->json($this->present($request->user()->reminderSetting));
    }

    public function update(UpdateReminderRequest $request)
    {
        $settings = ReminderSetting::updateOrCreate(
            ['user_id' => $request->user()->id],
            $request->validated(),
        );

        return response()->json($this->present($settings));
    }

    private function present(?ReminderSetting $s): array
    {
        return [
            'enabled'      => (bool) ($s?->enabled ?? false),
            'morning_at'   => $s?->slotTime('morning'),
            'afternoon_at' => $s?->slotTime('afternoon'),
            'evening_at'   => $s?->slotTime('evening'),
            'timezone'     => $s?->timezone,
            'channels'     => $s?->channels ?? ['in_app'],
        ];
    }
}
