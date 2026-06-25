<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AccountActivationNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Owns the email-verification / account-activation lifecycle. Tokens are random and
 * stored only as a sha256 hash with a configurable expiry (mirrors the password-reset
 * pattern), so the raw token never touches the database. Activation is single-use,
 * idempotent, and grants the Member monthly package via the existing TokenService.
 */
class AccountActivationService
{
    public function __construct(private TokenService $tokens) {}

    /** Outcome codes returned by activate(), so the controller picks the right view. */
    public const RESULT_ACTIVATED = 'activated';     // just activated now
    public const RESULT_ALREADY   = 'already_active'; // idempotent re-click
    public const RESULT_EXPIRED   = 'expired';        // token matched but past expiry
    public const RESULT_INVALID   = 'invalid';        // no such (live) token

    /**
     * Issue a fresh activation token for a pending user, persist its hash + expiry,
     * and return the raw token (to be emailed). Replaces any prior token.
     */
    public function issueToken(User $user): string
    {
        $token = Str::random(64);

        $user->forceFill([
            'activation_token'      => hash('sha256', $token),
            'activation_expires_at' => Carbon::now()->addHours(
                (int) config('account.verification_expires_hours', 24)
            ),
        ])->save();

        return $token;
    }

    /** Email the activation link to the user (best-effort; token stays valid if mail fails). */
    public function sendActivationEmail(User $user, string $token): void
    {
        try {
            Notification::route('mail', $user->email)
                ->notify(new AccountActivationNotification($token, $user->name));
        } catch (\Throwable $e) {
            Log::warning('activation.email_failed', ['user_id' => $user->id]);
        }
    }

    /** Convenience: issue a token and send it in one step. */
    public function startVerification(User $user): void
    {
        $this->sendActivationEmail($user, $this->issueToken($user));
    }

    /**
     * Validate a raw activation token and activate the matching account. Single-use
     * (the token is cleared on success) and idempotent: a second click on an
     * already-activated account reports success without re-granting tokens.
     *
     * Replay protection: once activated the hashed token is null, so the same link can
     * never re-match. An expired-but-unconsumed token reports EXPIRED.
     */
    public function activate(string $rawToken): array
    {
        $hash = hash('sha256', $rawToken);

        $user = User::where('activation_token', $hash)->first();

        if (! $user) {
            // No live token matches. This may be a replay of an already-consumed link;
            // we cannot distinguish that from a bogus token without leaking info, so
            // report a generic invalid result.
            return ['result' => self::RESULT_INVALID, 'user' => null];
        }

        if ($user->activation_expires_at !== null && $user->activation_expires_at->isPast()) {
            Log::info('activation.expired', ['user_id' => $user->id]);
            return ['result' => self::RESULT_EXPIRED, 'user' => $user];
        }

        if ($user->isActive() && $user->email_verified_at !== null) {
            // Already activated previously — clear the lingering token and succeed.
            $user->forceFill(['activation_token' => null, 'activation_expires_at' => null])->save();
            return ['result' => self::RESULT_ALREADY, 'user' => $user];
        }

        $user->forceFill([
            'status'                => User::STATUS_ACTIVE,
            'email_verified_at'     => Carbon::now(),
            'activation_token'      => null,
            'activation_expires_at' => null,
        ])->save();

        // Grant the Member monthly package through the existing ledger-backed path.
        // refillMonthly sets the wallet to the plan allowance, stamps the refill time
        // (so the monthly job won't double-grant this cycle), and writes a ledger row.
        $this->tokens->refillMonthly($user);

        Log::info('activation.activated', ['user_id' => $user->id]);

        return ['result' => self::RESULT_ACTIVATED, 'user' => $user];
    }
}
