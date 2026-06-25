<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Rate limiters are keyed by IP and share the array cache across the whole
        // test process, so repeated auth calls would spuriously 429. We assert
        // behaviour, not throttling, so disable the throttle middleware globally.
        $this->withoutMiddleware([
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
            // SPA login establishes a session; CSRF is exercised by the SPA, not here.
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);

        // Make requests look like they come from the SPA so Sanctum's stateful
        // middleware starts a session (login()/logout() call $request->session()).
        $this->withHeader('Origin', config('app.url'));
    }

    /**
     * Create a persisted user with sensible defaults. Tests override only what they
     * care about (status, role, plan, …). No factory/faker dependency is needed.
     */
    protected function makeUser(array $overrides = []): User
    {
        $attrs = array_merge([
            'name'              => 'Test User',
            'email'             => 'user_' . Str::random(12) . '@example.com',
            'password'          => Hash::make('password123'),
            'timezone'          => 'UTC',
            'music_source'      => 'hymn_sung',
            'status'            => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ], $overrides);

        // email_verified_at / subscription_status are not mass-assignable on the model,
        // so apply them via forceFill after create.
        $guarded = array_intersect_key($attrs, array_flip(['email_verified_at', 'subscription_status']));
        $user = User::create(array_diff_key($attrs, $guarded));
        if ($guarded) {
            $user->forceFill($guarded)->save();
        }

        return $user;
    }
}
