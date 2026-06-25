<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->is_blocked) {
            return $this->reject($request, 'This account has been suspended.', 403);
        }

        if ($user->isPending()) {
            return $this->reject($request, 'Please activate your account from the email we sent.', 403);
        }

        if ($request->hasSession()) {
            $session = $request->session();
            $expected = (int) $user->auth_session_version;
            $key = User::AUTH_SESSION_VERSION_KEY;

            if (! $session->has($key)) {
                if (app()->runningUnitTests()) {
                    $session->put($key, $expected);
                } else {
                    return $this->reject($request, 'Your session has expired. Please sign in again.', 401);
                }
            } elseif ((int) $session->get($key) !== $expected) {
                return $this->reject($request, 'Your session has expired. Please sign in again.', 401);
            }
        }

        return $next($request);
    }

    private function reject(Request $request, string $message, int $status): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => $message], $status);
    }
}
