<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request, \App\Services\AccountActivationService $activation): JsonResponse
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'email'        => ['required', 'email', 'unique:users,email'],
            'password'     => ['required', Password::defaults()],
            'timezone'     => ['nullable', 'string', 'max:64'],
            'music_source' => ['nullable', 'in:' . implode(',', Setting::MUSIC_SOURCES)],
        ]);

        // Provision a PENDING account: it exists but cannot log in until the user clicks
        // the activation link emailed below. No auto-login, no tokens granted yet — the
        // Member package is granted on activation (App\Services\AccountActivationService).
        $user = User::create([
            'name'         => $data['name'],
            'email'        => $data['email'],
            'password'     => Hash::make($data['password']),
            'timezone'     => $data['timezone'] ?? 'UTC',
            'music_source' => $data['music_source'] ?? 'hymn_sung',
            'status'       => User::STATUS_PENDING,
        ]);

        $activation->startVerification($user);

        return response()->json([
            'message' => 'Please check your email to activate your account.',
        ], 201);
    }

    /**
     * Provision a walk-up worshipper so the intake flow needs no login. Name and
     * email are both optional: a worshipper may introduce themselves, give an email
     * so we can welcome them back, or stay anonymous — in which case we assign a
     * friendly, non-duplicate visitor name. Establishes an HttpOnly session cookie;
     * the guest may later register to claim the account. Honors an optional
     * music_source so the choice survives the very first session.
     */
    public function guest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => ['nullable', 'string', 'max:255'],
            'email'        => ['nullable', 'email', 'max:255'],
            'music_source' => ['nullable', 'in:' . implode(',', Setting::MUSIC_SOURCES)],
        ]);

        $name = trim($data['name'] ?? '');
        // Whether the worshipper actually gave a name. If blank, we mint a friendly
        // visitor name for display, but the service must stay anonymous in the spoken
        // content (prayer/sermon/benediction never use this placeholder).
        $nameProvided = $name !== '';
        if (! $nameProvided) {
            $name = $this->uniqueVisitorName();
        }

        // Use the worshipper's email only if it's free; otherwise (blank or already
        // claimed) fall back to an internal guest address so the account stays
        // anonymous and we never collide with a registered user.
        $email = $data['email'] ?? null;
        if (! $email || User::where('email', $email)->exists()) {
            $email = 'guest_' . Str::uuid() . '@guest.local';
        }

        $user = User::create([
            'name'         => $name,
            'name_provided'=> $nameProvided,
            'email'        => $email,
            'password'     => Hash::make(Str::random(40)),
            'timezone'     => 'UTC',
            'music_source' => $data['music_source'] ?? 'hymn_sung',
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $user], 201);
    }

    /**
     * A friendly, human-readable visitor name ("Quiet Pilgrim 482") guaranteed not
     * to collide with an existing user's name. Falls back to a uuid suffix in the
     * unlikely event the adjective/noun pool keeps colliding.
     */
    private function uniqueVisitorName(): string
    {
        $adjectives = ['Quiet', 'Hopeful', 'Gentle', 'Faithful', 'Joyful', 'Humble', 'Grateful', 'Peaceful', 'Kind', 'Bright'];
        $nouns      = ['Pilgrim', 'Friend', 'Seeker', 'Traveler', 'Soul', 'Visitor', 'Disciple', 'Neighbor'];

        for ($attempt = 0; $attempt < 25; $attempt++) {
            $name = $adjectives[array_rand($adjectives)]
                . ' ' . $nouns[array_rand($nouns)]
                . ' ' . random_int(100, 9999);

            if (! User::where('name', $name)->exists()) {
                return $name;
            }
        }

        return 'Visitor ' . Str::upper(Str::random(6));
    }

    /** The currently authenticated user — used by the SPA to greet returnees. */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    /**
     * Public "who am I" probe for the SPA's initial load. Unlike /me (behind
     * auth:sanctum, which 401s for anonymous visitors and litters the console),
     * this returns 200 with user:null when there's no session — so the frontend
     * can resolve auth state cleanly without an expected-error round-trip.
     */
    public function session(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'authenticated' => (bool) $user,
            'user'          => $user ? $this->userPayload($user) : null,
        ]);
    }

    /** The identity + entitlements payload shared by /me and /auth/session. */
    private function userPayload(User $user): array
    {
        $isGuest  = str_ends_with($user->email, '@guest.local');
        $features = \App\Services\FeatureService::for($user);

        return [
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $isGuest ? null : $user->email,
            'is_admin'       => $user->isAdmin(),
            'role'           => $user->role(),
            'is_guest'       => $isGuest,
            'music_source'   => $user->music_source,
            'permissions'    => PermissionService::forUser($user),
            // Subscription + wallet, so the SPA can hide ads, show the token gauge,
            // and surface upgrade prompts without an extra round-trip.
            'plan'              => $user->plan()->value,
            'subscription'      => $user->subscriptionStatus()->value,
            'is_premium'        => $user->isPremium(),
            'shows_ads'         => $features->showsAds(),
            'token_balance'     => (int) $user->token_balance,
            'monthly_allowance' => $features->monthlyAllowance(),
            // Whether self-serve upgrades are possible in this deployment, so the
            // account UI can degrade gracefully when billing is unconfigured.
            'billing_enabled'   => self::billingEnabled(),
        ];
    }

    /** True only when a payment provider is fully configured (key + price id). */
    public static function billingEnabled(): bool
    {
        return filled(config('services.stripe.secret'))
            && filled(config('tokens.stripe_premium_price'));
    }

    /** Let a registered user update their display name. */
    public function updateName(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
        ]);

        $request->user()->update(['name' => $data['name'], 'name_provided' => true]);

        return response()->json(['ok' => true, 'name' => $data['name']]);
    }

    /**
     * Generate a password-reset token and store it. If the app has outbound
     * mail configured (MAIL_MAILER != 'log'/'array') the link is emailed;
     * otherwise the token is returned so an admin can share it out-of-band.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $data['email'])->first();

        // Never reveal whether the email exists — same response either way.
        if (! $user || str_ends_with($user->email, '@guest.local')) {
            return response()->json(['message' => 'If that address is registered, a reset link has been sent.']);
        }

        $token   = Str::random(64);
        $expires = Carbon::now()->addHours(2);
        $user->update([
            'password_reset_token'      => hash('sha256', $token),
            'password_reset_expires_at' => $expires,
        ]);

        // Send email if a real mailer is configured.
        $mailer = config('mail.default', 'log');
        if (! in_array($mailer, ['log', 'array'])) {
            try {
                \Illuminate\Support\Facades\Notification::route('mail', $user->email)
                    ->notify(new \App\Notifications\PasswordResetNotification($token, $user->name));
            } catch (\Throwable) {
                // Mail failed — fall through, token is still stored.
            }
        }

        return response()->json(['message' => 'If that address is registered, a reset link has been sent.']);
    }

    /** Use a valid reset token to set a new password. */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'        => ['required', 'string', 'size:64'],
            'new_password' => ['required', 'string', Password::defaults()],
        ]);

        $user = User::where('password_reset_token', hash('sha256', $data['token']))
            ->where('password_reset_expires_at', '>', Carbon::now())
            ->first();

        if (! $user) {
            return response()->json(['message' => 'This reset link is invalid or has expired.'], 422);
        }

        $user->update([
            'password'                  => Hash::make($data['new_password']),
            'password_reset_token'      => null,
            'password_reset_expires_at' => null,
        ]);

        // Invalidate all existing sessions so the old password can no longer be used.
        $user->tokens()->delete();

        return response()->json(['message' => 'Password updated. Please log in with your new password.']);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 422);
        }

        if ($user->is_blocked) {
            return response()->json(['message' => 'This account has been suspended.'], 403);
        }

        // Gate unverified accounts: a registrant who has not yet clicked the activation
        // link must not be able to sign in. Checked after the credential check so this
        // never reveals whether an email exists for a wrong password.
        if ($user->isPending()) {
            return response()->json([
                'message' => 'Please activate your account from the email we sent.',
            ], 403);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $user]);
    }

    public function logout(Request $request): JsonResponse
    {
        // SPA session logout must go through the stateful "web" guard. The default
        // guard for these routes resolves to Sanctum's RequestGuard, which has no
        // logout() — calling Auth::logout() there 500s and the server session
        // survives. Invalidating the session is what actually signs the user out.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['message' => 'Logged out']);
    }

    /** Change the authenticated user's password after verifying the current one. */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password'     => ['required', 'string', Password::defaults(), 'different:current_password'],
        ]);

        if (! Hash::check($data['current_password'], $request->user()->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $request->user()->update(['password' => Hash::make($data['new_password'])]);

        return response()->json(['message' => 'Password updated.']);
    }

    /**
     * Save a real email on a guest account whose email is still @guest.local.
     * Called from the frontend when a walk-up worshipper provides an email
     * while scheduling a service but already has a token from a prior visit.
     */
    public function updateEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        // Only guests without a real email may use this endpoint; registered
        // users own their email address and must not be able to change it here.
        if (! str_ends_with($user->email, '@guest.local')) {
            return response()->json(['message' => 'Email is already set.'], 422);
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);

        $user->update(['email' => $data['email']]);

        return response()->json(['user' => $user]);
    }

    /** Let a logged-in user switch their default media source (Suno vs YouTube). */
    public function updateMusicSource(Request $request): JsonResponse
    {
        $data = $request->validate([
            'music_source' => ['required', 'in:' . implode(',', Setting::enabledMusicSources())],
        ]);
        $request->user()->update($data);

        return response()->json(['user' => $request->user()]);
    }

    /** Let a logged-in user choose their presenter gender (male/female avatar+voice). */
    public function updatePresenterGender(Request $request): JsonResponse
    {
        $data = $request->validate([
            'presenter_gender' => ['required', 'in:male,female'],
        ]);
        $request->user()->update($data);

        return response()->json(['user' => $request->user()]);
    }
}
