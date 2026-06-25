<?php

namespace App\Http\Controllers;

use App\Services\FeatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only token wallet surface for the account page: current balance, plan allowance,
 * and recent ledger history. Spending happens server-side inside the AI flows via
 * TokenService — there is deliberately no client endpoint that debits tokens directly.
 */
class TokenController extends Controller
{
    /** Balance + allowance summary. */
    public function balance(Request $request): JsonResponse
    {
        $user = $request->user();
        $allowance = FeatureService::for($user)->monthlyAllowance();

        return response()->json([
            'balance'           => (int) $user->token_balance,
            'monthly_allowance' => $allowance,
            // "used this period" for the dashboard gauge (clamped at 0 for top-ups).
            'used'              => max(0, $allowance - (int) $user->token_balance),
            'refilled_at'       => $user->tokens_refilled_at,
        ]);
    }

    /** Recent wallet movements (newest first). */
    public function history(Request $request): JsonResponse
    {
        $entries = $request->user()->tokenLedger()
            ->latest('created_at')
            ->limit(50)
            ->get(['amount', 'balance_after', 'type', 'reference', 'created_at']);

        return response()->json(['entries' => $entries]);
    }
}
