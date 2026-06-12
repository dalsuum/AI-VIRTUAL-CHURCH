<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Allows admin, moderator, and presenter roles into the staff console routes. */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if(
            ! $user || ! in_array($user->role(), ['admin', 'moderator', 'presenter']),
            403,
            'Staff access required.'
        );

        return $next($request);
    }
}
