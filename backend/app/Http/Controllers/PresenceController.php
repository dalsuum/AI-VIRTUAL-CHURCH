<?php

namespace App\Http\Controllers;

use App\Domains\Accounts\Services\PresenceService;
use App\Enums\PresenceActivity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

/**
 * Presence read/write — always via PresenceService (never the model), so the Phase 6
 * Redis swap touches nothing here. Cross-user reads are visibility-filtered inside the
 * service through PrivacyGate.
 */
class PresenceController extends Controller
{
    public function __construct(private readonly PresenceService $presence)
    {
    }

    /** Heartbeat: I'm online (optionally doing a specific activity). */
    public function heartbeat(Request $request)
    {
        $data = $request->validate([
            'activity'     => ['nullable', new Enum(PresenceActivity::class)],
            'activity_ref' => ['nullable', 'string', 'max:64'],
        ]);

        $this->presence->heartbeat(
            $request->user(),
            isset($data['activity']) ? PresenceActivity::from($data['activity']) : null,
            $data['activity_ref'] ?? null,
        );

        return response()->noContent();
    }

    /** My own presence. */
    public function me(Request $request)
    {
        return response()->json($this->presence->forSelf($request->user()));
    }

    /** Visible presence of my friends, keyed by user id. */
    public function friends(Request $request)
    {
        return response()->json(['presence' => $this->presence->friendsPresence($request->user())]);
    }

    /** A single member's presence, if privacy permits (else 404 — no probing). */
    public function show(Request $request, User $user)
    {
        $presence = $this->presence->visibleTo($request->user(), $user);
        abort_if($presence === null, 404);

        return response()->json($presence);
    }
}
