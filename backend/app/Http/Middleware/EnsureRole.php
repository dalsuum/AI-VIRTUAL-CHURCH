<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role gate for individual routes. Usage in routes:
 *   ->middleware('role:moderator')   // moderator OR admin
 *   ->middleware('role:presenter')   // presenter, moderator, OR admin
 *
 * The check is hierarchical: any role at or above the required level passes.
 */
class EnsureRole
{
    // Privilege order — higher index = more access.
    private const ORDER = ['guest', 'member', 'presenter', 'moderator', 'admin'];

    public function handle(Request $request, Closure $next, string $required): Response
    {
        $user = $request->user();
        $userLevel     = array_search($user?->role() ?? 'guest', self::ORDER);
        $requiredLevel = array_search($required, self::ORDER);

        abort_if(
            $userLevel === false || $requiredLevel === false || $userLevel < $requiredLevel,
            403,
            "Requires '{$required}' role or higher."
        );

        return $next($request);
    }
}
