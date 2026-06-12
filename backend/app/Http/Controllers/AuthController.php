<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'email'        => ['required', 'email', 'unique:users,email'],
            'password'     => ['required', Password::defaults()],
            'timezone'     => ['nullable', 'string', 'max:64'],
            'music_source' => ['nullable', 'in:' . implode(',', Setting::MUSIC_SOURCES)],
        ]);

        $user = User::create([
            'name'         => $data['name'],
            'email'        => $data['email'],
            'password'     => Hash::make($data['password']),
            'timezone'     => $data['timezone'] ?? 'UTC',
            'music_source' => $data['music_source'] ?? 'hymn_sung',
        ]);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json(['user' => $user, 'token' => $token], 201);
    }

    /**
     * Provision a walk-up worshipper so the intake flow needs no login. Name and
     * email are both optional: a worshipper may introduce themselves, give an email
     * so we can welcome them back, or stay anonymous — in which case we assign a
     * friendly, non-duplicate visitor name. Mints a Sanctum token either way; the
     * guest may later register to claim the account. Honors an optional
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

        $token = $user->createToken('api')->plainTextToken;

        return response()->json(['user' => $user, 'token' => $token], 201);
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
        $user = $request->user();

        return response()->json([
            'user' => [
                'id'           => $user->id,
                'name'         => $user->name,
                'email'        => $user->email,
                'is_admin'     => $user->is_admin,
                'music_source' => $user->music_source,
            ],
        ]);
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

        $token = $user->createToken('api')->plainTextToken;

        return response()->json(['user' => $user, 'token' => $token]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
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
