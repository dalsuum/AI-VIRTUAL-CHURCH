<?php

namespace App\Http\Controllers;

use App\Domains\Accounts\Models\PrivacySetting;
use App\Enums\Visibility;
use App\Http\Requests\UpdatePrivacyRequest;
use Illuminate\Http\Request;

/**
 * The authenticated user's own privacy settings. Defaults (friends-visible, not
 * incognito) are returned when no row exists yet, matching what PrivacyGate assumes,
 * so the client always sees a complete, consistent picture.
 */
class PrivacyController extends Controller
{
    public function show(Request $request)
    {
        return response()->json($this->present($request->user()->privacy));
    }

    public function update(UpdatePrivacyRequest $request)
    {
        $settings = PrivacySetting::updateOrCreate(
            ['user_id' => $request->user()->id],
            $request->validated(),
        );

        return response()->json($this->present($settings));
    }

    private function present(?PrivacySetting $s): array
    {
        return [
            'profile_visibility'  => ($s?->profile_visibility  ?? Visibility::FRIENDS)->value,
            'activity_visibility' => ($s?->activity_visibility ?? Visibility::FRIENDS)->value,
            'presence_visibility' => ($s?->presence_visibility ?? Visibility::FRIENDS)->value,
            'friend_only_mode'    => (bool) ($s?->friend_only_mode ?? false),
            'incognito'           => (bool) ($s?->incognito ?? false),
        ];
    }
}
