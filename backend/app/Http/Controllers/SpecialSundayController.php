<?php

namespace App\Http\Controllers;

use App\Services\SpecialSundayResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, unauthenticated endpoint that tells the intake/home view which special
 * Sunday (if any) is active right now, localized to the requested service
 * language. Returns { active: false } outside any window. Drives the highlight
 * card in IntakeForm.vue.
 */
class SpecialSundayController extends Controller
{
    public function current(Request $request, SpecialSundayResolver $resolver): JsonResponse
    {
        $language = in_array($request->query('language'), ['en', 'my', 'td'], true)
            ? $request->query('language')
            : 'en';

        $payload = $resolver->currentPayload($language);

        if ($payload === null) {
            return response()->json(['active' => false]);
        }

        return response()->json([
            'active'      => true,
            'observance'  => $payload,
        ]);
    }
}
