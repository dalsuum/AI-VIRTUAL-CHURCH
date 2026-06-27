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
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
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
    })->create();
