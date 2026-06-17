<?php

namespace App\Http\Controllers;

use App\Models\MusicTrack;
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
        $secret = (string) config('services.worker.secret', '');
        abort_unless(
            strlen($secret) >= 32 && hash_equals($secret, (string) $request->header('X-Worker-Secret')),
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
            // Public-domain hymn verses to show on screen (hymn music sources).
            'lyrics'        => ['nullable', 'string'],
            // Optional LRC line timings ([{time, line_index}]) paired with `lyrics`.
            'timings'           => ['nullable', 'array'],
            'timings.*.time'       => ['required_with:timings', 'numeric'],
            'timings.*.line_index' => ['required_with:timings', 'integer', 'min:0'],
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
                'lyrics'       => $data['lyrics']       ?? $existing?->lyrics,
                'timings'      => $data['timings']      ?? $existing?->timings,
                'status'       => 'ready',
                'ready_at'     => now(),
            ],
        );

        // Backfill scripture_ref into the intake the first time the scripture segment
        // arrives. The worker stores "{ref}\n\n{verse text}" in text_payload; the first
        // line is the reference (e.g. "Psalm 23:1-4"). We only write if the intake has
        // no ref yet so a re-run of the scripture segment doesn't clobber a manual value.
        if ($data['segment'] === 'scripture' && !empty($data['text_payload'])) {
            $ref = trim(explode("\n", $data['text_payload'])[0]);
            $intake = $session->intake;
            if ($ref && $intake && !$intake->scripture_ref) {
                $intake->update(['scripture_ref' => $ref]);
            }
        }

        // Broadcast to the client over WebSockets (Laravel Reverb / Echo).
        // event(new AssetReady($session->session_token, $asset));

        // Myanmar/Tedim services are now generated directly in their target language
        // by the Python worker. Do not dispatch the old post-generation localization
        // jobs here: they can run for many minutes and block DispatchServiceJob on the
        // single Laravel queue, leaving new services with no pages. Keep the status
        // markers ready for older UI/admin code that still reads them.
        if ($session->language === 'td' && $session->tedim_status === null) {
            $session->update(['tedim_status' => 'ready']);
        }

        if ($session->language === 'my' && $session->burmese_status === null) {
            $session->update(['burmese_status' => 'ready']);
        }

        return response()->json(['ok' => true, 'asset_id' => $asset->id]);
    }

    /**
     * Register a freshly composed Suno track in the reusable, language-and-mood-keyed pool. The
     * worker calls this once per fresh generation; deduped by provider_ref so a
     * retried task can't double-insert. Reused tracks already exist here, so they
     * aren't re-posted.
     */
    public function musicTrack(Request $request): JsonResponse
    {
        $secret = (string) config('services.worker.secret', '');
        abort_unless(
            strlen($secret) >= 32 && hash_equals($secret, (string) $request->header('X-Worker-Secret')),
            403
        );

        $data = $request->validate([
            'mood'         => ['required', 'string', 'max:100'],
            'language'     => ['nullable', 'string', 'max:5'],
            'provider_ref' => ['required', 'string'],
            'storage_key'  => ['required', 'string'],   // RAW object key, not a presigned URL
            'title'        => ['nullable', 'string'],
            'lyrics'       => ['nullable', 'string'],
        ]);

        $language = in_array($data['language'] ?? 'en', ['en', 'my', 'td'], true)
            ? ($data['language'] ?? 'en')
            : 'en';

        $track = MusicTrack::updateOrCreate(
            ['provider_ref' => $data['provider_ref']],
            [
                'mood'        => $data['mood'],
                'language'    => $language,
                'storage_key' => $data['storage_key'],
                'title'       => $data['title'] ?? null,
                'lyrics'      => $data['lyrics'] ?? null,
                'source'      => 'suno',
            ],
        );

        return response()->json(['ok' => true, 'track_id' => $track->id]);
    }
}
