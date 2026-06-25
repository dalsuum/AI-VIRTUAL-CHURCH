<?php

namespace App\Http\Middleware;

use App\Services\GuestUsageService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the guest "one free use per service" quota. Applies only to anonymous
 * (@guest.local) accounts; registered users fall through to the token middleware.
 * It only *blocks* here — the consumption is recorded by the controller after a
 * successful run, so a failed request never burns the visitor's single free use.
 *
 * Usage:  ->middleware('guest.limit:study')
 */
class GuestRateLimiter
{
    public function __construct(private GuestUsageService $guests) {}

    public function handle(Request $request, Closure $next, string $service): Response
    {
        $user = $request->user();

        if ($user && $user->isGuestAccount() && $this->guests->hasUsed($request, $service)) {
            return response()->json([
                'message'     => 'You have used your free guest access for this feature. Please register or upgrade to continue.',
                'reason'      => 'guest_limit',
                'service'     => $service,
                'upgrade_url' => '/account',
            ], 402);
        }

        return $next($request);
    }
}
