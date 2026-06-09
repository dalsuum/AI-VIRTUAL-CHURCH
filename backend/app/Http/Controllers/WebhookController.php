<?php

namespace App\Http\Controllers;

use App\Models\ServiceAsset;
use App\Models\ServiceSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Internal endpoint hit by the Python workers. Protected by a shared secret header
 * (WORKER_WEBHOOK_SECRET) rather than user auth, since no user is in the loop here.
 */
class WebhookController extends Controller
{
    public function assetReady(Request $request): JsonResponse
    {
        abort_unless(
            hash_equals(config('services.worker.secret', ''), (string) $request->header('X-Worker-Secret')),
            403
        );

        $data = $request->validate([
            'session_token' => ['required', 'string'],
            'segment'       => ['required', 'string'],
            // Nullable: a narration-only pass enriches an existing row's audio_key
            // without restating (or clobbering) the segment's primary asset_type.
            'asset_type'    => ['nullable', 'in:video,audio,text,url,youtube'],
            'storage_key'   => ['nullable', 'string'],
            'audio_key'     => ['nullable', 'string'],
            'provider_ref'  => ['nullable', 'string'],
            'text_payload'  => ['nullable', 'string'],
        ]);

        $session = ServiceSession::where('session_token', $data['session_token'])->firstOrFail();

        // A segment can arrive in several passes: first its text, then (optionally) an
        // avatar video and/or text-to-speech narration of that text. Each pass must
        // enrich the row, not erase the others — so absent fields fall back to
        // whatever is already stored.
        $existing = ServiceAsset::firstWhere([
            'session_id' => $session->id,
            'segment'    => $data['segment'],
        ]);

        $asset = ServiceAsset::updateOrCreate(
            ['session_id' => $session->id, 'segment' => $data['segment']],
            [
                'asset_type'   => $data['asset_type']   ?? $existing?->asset_type ?? 'audio',
                'storage_key'  => $data['storage_key']  ?? $existing?->storage_key,
                'audio_key'    => $data['audio_key']    ?? $existing?->audio_key,
                'provider_ref' => $data['provider_ref'] ?? $existing?->provider_ref,
                'text_payload' => $data['text_payload'] ?? $existing?->text_payload,
                'status'       => 'ready',
                'ready_at'     => now(),
            ],
        );

        // Broadcast to the client over WebSockets (Laravel Reverb / Echo).
        // event(new AssetReady($session->session_token, $asset));

        return response()->json(['ok' => true, 'asset_id' => $asset->id]);
    }
}
