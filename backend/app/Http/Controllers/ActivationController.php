<?php

namespace App\Http\Controllers;

use App\Services\AccountActivationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * GET /activate?token=… — the link target from the activation email. Validates the
 * token, activates the account (idempotently), grants the Member package, and renders
 * a self-contained result page with a "Continue to Login" button. Rate-limited at the
 * route level to blunt token-guessing.
 */
class ActivationController extends Controller
{
    public function activate(Request $request, AccountActivationService $activation): View
    {
        $loginUrl = rtrim((string) config('app.url'), '/') . '/#login';
        $token    = (string) $request->query('token', '');

        // Activation tokens are exactly 64 chars (Str::random(64)). Reject anything else
        // up front so malformed input never reaches a DB lookup.
        if (strlen($token) !== 64) {
            return view('activate', [
                'ok'       => false,
                'heading'  => 'Invalid activation link',
                'message'  => 'This activation link is not valid. Please check the link in your email or register again.',
                'loginUrl' => $loginUrl,
            ]);
        }

        $outcome = $activation->activate($token);

        return view('activate', $this->present($outcome['result'], $loginUrl));
    }

    /** Map an activation outcome to the page copy. */
    private function present(string $result, string $loginUrl): array
    {
        return match ($result) {
            AccountActivationService::RESULT_ACTIVATED => [
                'ok'      => true,
                'heading' => 'Account Activated',
                'message' => 'Your account is now active and your monthly Member tokens have been added. You can sign in below.',
                'loginUrl'=> $loginUrl,
            ],
            AccountActivationService::RESULT_ALREADY => [
                'ok'      => true,
                'heading' => 'Account Already Active',
                'message' => 'This account has already been activated. You can sign in below.',
                'loginUrl'=> $loginUrl,
            ],
            AccountActivationService::RESULT_EXPIRED => [
                'ok'      => false,
                'heading' => 'Activation link expired',
                'message' => 'This activation link has expired. Please register again to receive a fresh link.',
                'loginUrl'=> $loginUrl,
            ],
            default => [
                'ok'      => false,
                'heading' => 'Invalid activation link',
                'message' => 'This activation link is not valid or has already been used. Please register again if you still need to activate your account.',
                'loginUrl'=> $loginUrl,
            ],
        };
    }
}
