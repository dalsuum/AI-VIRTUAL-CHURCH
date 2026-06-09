<?php

namespace App\Http\Controllers;

use App\Models\ServiceSession;
use App\Services\OfferingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * The offering segment (Phase 4 — Commerce). Two surfaces:
 *
 *  - createIntent: authenticated. The worshipper asks to give; we open a Stripe
 *    PaymentIntent and return its client_secret. The browser confirms the card
 *    directly with Stripe — no card data reaches us.
 *
 *  - webhook: public but Stripe-signature-verified (mirrors the shared-secret
 *    pattern the worker webhook uses). This is the ONLY place a charge is written
 *    to the ledger, because it's the only signal we trust that money actually moved.
 */
class OfferingController extends Controller
{
    public function __construct(private OfferingService $offerings) {}

    public function createIntent(Request $request, string $token): JsonResponse
    {
        $session = ServiceSession::where('session_token', $token)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $data = $request->validate([
            'amount'     => ['required', 'integer', 'min:100', 'max:1000000'], // minor units (cents)
            'allocation' => ['required', 'in:operations,charity,missions'],
        ]);

        $intent = $this->offerings->createIntent($session, $data['amount'], $data['allocation']);

        return response()->json($intent, 201);
    }

    public function webhook(Request $request): JsonResponse
    {
        $secret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                (string) $secret,
            );
        } catch (\UnexpectedValueException | SignatureVerificationException $e) {
            // Bad payload or forged signature — reject without touching the ledger.
            abort(400, 'Invalid Stripe signature.');
        }

        if ($event->type === 'payment_intent.succeeded') {
            $this->offerings->record($event->data->object);
        }

        // Acknowledge everything else so Stripe stops retrying events we ignore.
        return response()->json(['received' => true]);
    }
}
