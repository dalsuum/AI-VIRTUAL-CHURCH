<?php

namespace App\Http\Middleware;

use App\Services\TokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pre-flight token check for registered (member/premium) users. Guests are governed by
 * GuestRateLimiter instead and are skipped here. This only *verifies* the balance is
 * sufficient and rejects early with a clear 402; the actual debit happens via
 * TokenService::reserve() inside the controller so it's atomic with the AI request.
 *
 * Usage:  ->middleware('tokens:study')
 */
class RequireTokens
{
    public function __construct(private TokenService $tokens) {}

    public function handle(Request $request, Closure $next, string $service): Response
    {
        $user = $request->user();

        // Guests don't have a wallet; their access is gated by guest.limit.
        if ($user && ! $user->isGuestAccount()) {
            $cost = $this->tokens->cost($service);
            if ((int) $user->token_balance < $cost) {
                return response()->json([
                    'message'     => 'You have run out of tokens for this billing period.',
                    'reason'      => 'insufficient_tokens',
                    'required'    => $cost,
                    'balance'     => (int) $user->token_balance,
                    'upgrade_url' => '/account',
                ], 402);
            }
        }

        return $next($request);
    }
}
