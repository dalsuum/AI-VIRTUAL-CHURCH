<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;

/**
 * Public, unauthenticated app configuration consumed by the intake form before a
 * worshipper has a session: which moods to offer, which music sources are enabled,
 * and whether scheduling is available. Admins shape these via the admin settings.
 */
class ConfigController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'moods'              => Setting::moods(),
            'music_sources'      => Setting::enabledMusicSources(),
            'scheduling_enabled' => Setting::schedulingEnabled(),
        ]);
    }
}
