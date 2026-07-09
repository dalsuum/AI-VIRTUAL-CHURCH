<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One configured Stripe client, resolved wherever it's type-hinted
        // (e.g. OfferingService). Keeps the secret key out of the rest of the app.
        // Pass the key inside an array config (not a bare string): the array form
        // tolerates a null api_key, so the client still constructs when Stripe is
        // unconfigured in this environment. Read-only paths (account/subscription
        // status) then work; only calls that actually hit Stripe (checkout/webhook)
        // fail — and only when invoked. A bare string/empty key throws on construct,
        // which previously 500'd every endpoint whose controller type-hints billing.
        $this->app->singleton(StripeClient::class, function () {
            return new StripeClient(['api_key' => config('services.stripe.secret') ?: null]);
        });

        // The billing seam resolves to Stripe today; swap here to add a provider.
        $this->app->bind(
            \App\Services\Billing\BillingProvider::class,
            \App\Services\Billing\StripeProvider::class,
        );
    }

    public function boot(): void
    {
        // Deploy-time config sanity: surface a half-configured billing setup so a
        // missing key is visible in deploy/cache logs rather than only failing at
        // a user's checkout. Console-only so it never logs on every web request.
        if ($this->app->runningInConsole()) {
            $hasSecret = filled(config('services.stripe.secret'));
            $hasPrice  = filled(config('tokens.stripe_premium_price'));
            if ($hasSecret xor $hasPrice) {
                logger()->warning('Billing partially configured: STRIPE_SECRET and STRIPE_PREMIUM_PRICE_ID must both be set; premium checkout is disabled until then.');
            }
        }

        // Community: may the actor initiate contact with the target member? Delegates
        // to PrivacyGate via FriendshipPolicy so block/friend-only rules live in one place.
        Gate::define('friend-interact', [\App\Domains\Friends\Policies\FriendshipPolicy::class, 'interact']);

        // Invitation authorization (view/respond/cancel). Registered explicitly because
        // the model lives under app/Domains (outside policy auto-discovery).
        Gate::policy(
            \App\Domains\Invitations\Models\Invitation::class,
            \App\Domains\Invitations\Policies\InvitationPolicy::class,
        );

        // Church-scoped authorization (view/createSession/moderate/manage). Role
        // thresholds are owned by ChurchRole::atLeast, not the policy.
        Gate::policy(
            \App\Domains\Church\Models\Church::class,
            \App\Domains\Church\Policies\ChurchPolicy::class,
        );

        // Group-scoped authorization (view/create/manage/delete). Group leaders manage
        // their own group; church elders+ oversee all groups in their church.
        Gate::policy(
            \App\Domains\Groups\Models\Group::class,
            \App\Domains\Groups\Policies\GroupPolicy::class,
        );

        // Auth endpoints — keyed by login identifier + IP so users behind a shared
        // NAT don't lock each other out and an attacker can't drain one IP's budget
        // by spraying unrelated accounts. Falls back to IP-only when no email is sent.
        RateLimiter::for('auth', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email', '')));
            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        // Intake triggers the full AI pipeline (LLM + TTS + music). Two guards:
        // a per-minute burst cap and a daily cap per authenticated user / IP.
        RateLimiter::for('intake', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();
            return [
                Limit::perMinute(3)->by($key),
                Limit::perDay(20)->by($key),
            ];
        });

        // Testimony submissions — held for moderation, but still limit spam.
        RateLimiter::for('testimony', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });
    }
}
