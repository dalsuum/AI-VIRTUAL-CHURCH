<?php

namespace App\Http\Middleware;

use App\Domains\Friends\Models\Friendship;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the platform-wide rule that a BLOCK overrides every other relationship:
 * blocked users cannot reach each other through any community feature (friend
 * requests, invitations, shared reading/worship/pastor sessions, prayer requests).
 *
 * Apply to any authenticated route that targets another member via a {user} route
 * parameter. Returns 404 (not 403) so a block is indistinguishable from a missing
 * user — neither side can probe whether the other blocked them.
 */
class EnsureNotBlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor  = $request->user();
        $target = $request->route('user');

        if ($actor && $target instanceof User
            && Friendship::blockExistsBetween($actor->id, $target->id)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return $next($request);
    }
}
