<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        // API/SSE clients must never be redirected to a `login` page — there isn't one.
        // The auth middleware only resolves the redirect for non-JSON requests, and
        // EventSource sends `Accept: text/event-stream` (not JSON), so the framework
        // default (route('login')) throws "Route [login] not defined" → 500 mid-stream.
        // Returning null makes it throw a clean AuthenticationException, rendered as 401
        // JSON by the handler below.
        $middleware->redirectGuestsTo(fn ($request) => $request->is('api/*') ? null : route('login'));
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        // Resolve + persist the interface locale (drives validation/notifications/mail).
        $middleware->append(\App\Http\Middleware\SetLocale::class);
        // The guest-quota cookie is a non-sensitive client-set UUID, read raw by
        // GuestUsageService; exempt it from cookie encryption so it isn't dropped.
        $middleware->encryptCookies(except: ['guest_id']);
        $middleware->alias([
            'admin'       => \App\Http\Middleware\EnsureAdmin::class,
            'staff'       => \App\Http\Middleware\EnsureStaff::class,
            'role'        => \App\Http\Middleware\EnsureRole::class,
            'account.usable' => \App\Http\Middleware\EnsureAccountIsUsable::class,
            'guest.limit' => \App\Http\Middleware\GuestRateLimiter::class,
            'tokens'      => \App\Http\Middleware\RequireTokens::class,
            'not.blocked' => \App\Http\Middleware\EnsureNotBlocked::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Render unauthenticated requests as a clean 401 JSON (pairs with the
        // redirectGuestsTo override above). Without this the handler falls back to a
        // login redirect for non-JSON requests — e.g. an SSE reconnect on an expired
        // session would 500 and re-trigger the client's reconnect loop.
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            return response()->json(['message' => $e->getMessage()], 401);
        });

        // A lost race for the last token(s) (reserve() under lockForUpdate) surfaces as
        // a clean 402, matching the RequireTokens pre-check, instead of a 500.
        $exceptions->render(function (\App\Exceptions\InsufficientTokensException $e, $request) {
            return response()->json([
                'message'     => 'You have run out of tokens for this billing period.',
                'reason'      => 'insufficient_tokens',
                'required'    => $e->required,
                'balance'     => $e->available,
                'upgrade_url' => '/account',
            ], 402);
        });

        // Illegal friendship state transition (e.g. accepting a non-existent request,
        // friending a blocked user). The exception carries the right status: 409 for a
        // state conflict, 403 for a block/privacy refusal.
        $exceptions->render(function (\App\Domains\Friends\Exceptions\FriendshipException $e, $request) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        });

        // Illegal invitation transition (responding to a terminal/expired invitation) or
        // a forbidden actor. 409 state conflict / 403 authority refusal.
        $exceptions->render(function (\App\Domains\Invitations\Exceptions\InvitationException $e, $request) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        });

        // Illegal reading action (enrolling while another plan is active, no active plan).
        $exceptions->render(function (\App\Domains\Bible\Exceptions\ReadingException $e, $request) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        });
    })->create();
