<?php

namespace App\Http\Controllers;

use App\Domains\Church\Models\Church;
use Illuminate\Http\Request;

/**
 * Read-only church surface for Phase 1: the churches I belong to, and (if I'm at least a
 * member) the roster of a church I'm in. Authorization runs through ChurchPolicy, whose
 * role thresholds are owned by ChurchRole::atLeast.
 */
class ChurchController extends Controller
{
    /** Churches the authenticated user is an active member of, with their role. */
    public function index(Request $request)
    {
        $churches = $request->user()->churchMemberships()
            ->where('status', \App\Domains\Church\Models\ChurchMembership::STATUS_ACTIVE)
            ->with('church')
            ->get()
            ->map(fn ($m) => [
                'id'   => $m->church_id,
                'name' => $m->church?->name,
                'role' => $m->role->value,
            ]);

        return response()->json(['churches' => $churches]);
    }

    /** Member roster — visible to any member of the church (ChurchPolicy::view). */
    public function members(Request $request, Church $church)
    {
        $this->authorize('view', $church);

        $members = $church->memberships()->with('user')->get()->map(fn ($m) => [
            'id'   => $m->user_id,
            'name' => $m->user?->name,
            'role' => $m->role->value,
        ]);

        return response()->json(['members' => $members]);
    }
}
