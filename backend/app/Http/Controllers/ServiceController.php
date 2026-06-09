<?php

namespace App\Http\Controllers;

use App\Jobs\DispatchServiceJob;
use App\Models\ServiceIntake;
use App\Models\ServiceSession;
use App\Services\CrisisInterceptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ServiceController extends Controller
{
    public function __construct(private CrisisInterceptService $crisis) {}

    /** Create a session. The media source is locked from the user's preference now. */
    public function start(Request $request): JsonResponse
    {
        $user = $request->user();

        $session = ServiceSession::create([
            'user_id'       => $user->id,
            'session_token' => Str::random(64),
            'status'        => 'initializing',
            'music_source'  => $user->music_source,
        ]);

        return response()->json([
            'session_token' => $session->session_token,
            'music_source'  => $session->music_source,
            'status'        => $session->status,
        ], 201);
    }

    /**
     * Receive the intake (mood + optional prayer text). Runs the crisis check first.
     * If clean, persists the intake and dispatches the AI pipeline.
     */
    public function intake(Request $request, string $token): JsonResponse
    {
        $session = ServiceSession::where('session_token', $token)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $data = $request->validate([
            'mood'         => ['required', 'string', 'max:100'],
            'prayer_text'  => ['nullable', 'string', 'max:5000'],
            // Optional: hold the service until a chosen future moment.
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);

        // SAFETY GATE — before anything is queued.
        $check = $this->crisis->inspect($session->session_token, $data['prayer_text'] ?? null);
        if ($check['intercepted']) {
            $session->update(['status' => 'abandoned']);
            return response()->json([
                'intercepted' => true,
                'resource'    => $check['resource'],
            ], 200);
        }

        $intake = ServiceIntake::updateOrCreate(
            ['session_id' => $session->id],
            ['mood' => $data['mood'], 'prayer_text' => $data['prayer_text'] ?? null],
        );

        // Scheduled for later: hold it for the scheduler (see DispatchDueServices).
        // Otherwise dispatch now and fan out to the Python workers immediately.
        if (! empty($data['scheduled_at'])) {
            $session->update(['status' => 'scheduled', 'scheduled_at' => $data['scheduled_at']]);

            return response()->json([
                'intercepted'   => false,
                'session_token' => $session->session_token,
                'intake_id'     => $intake->id,
                'status'        => 'scheduled',
                'scheduled_at'  => $session->scheduled_at?->toIso8601String(),
            ], 202);
        }

        $session->update(['status' => 'active', 'scheduled_at' => null]);
        DispatchServiceJob::dispatch($session->id);

        return response()->json([
            'intercepted'   => false,
            'session_token' => $session->session_token,
            'intake_id'     => $intake->id,
            'status'        => 'active',
        ], 202);
    }

    /** Poll endpoint (WebSocket is primary; this is the fallback). */
    public function show(Request $request, string $token): JsonResponse
    {
        $session = ServiceSession::with(['intake', 'assets'])
            ->where('session_token', $token)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $assets = $session->assets;

        // Music: the "worship" segment drives the player. Shape it to the
        // { asset_type, provider_ref, url } contract the Vue MusicPlayer expects.
        $worship = $assets->firstWhere('segment', 'worship');
        $music = $worship ? [
            'asset_type'   => $worship->asset_type,
            'provider_ref' => $worship->provider_ref,   // YouTube video id
            'url'          => $worship->storage_key,     // stored-audio key (presign later)
        ] : null;

        // Spoken/text segments, keyed by segment name for the client to render.
        // Keyed off text_payload (not asset_type) so the words survive even after a
        // segment is enriched with an avatar video, which flips asset_type to 'video'.
        // The "welcome" greeting is surfaced separately (countdown screen), not as a
        // service segment, so it's excluded here.
        $segments = $assets
            ->filter(fn ($a) => filled($a->text_payload) && $a->segment !== 'welcome')
            ->mapWithKeys(fn ($a) => [$a->segment => $a->text_payload]);

        // Personalized "welcome back" greeting shown on the countdown screen while
        // the rest of the service composes. Present only for registered worshippers.
        $welcome = $assets->firstWhere('segment', 'welcome')?->text_payload;

        // Optional talking-head avatar videos, keyed by segment. storage_key carries
        // a directly-playable (presigned) URL — see the worker's avatar.render().
        $videos = $assets
            ->where('asset_type', 'video')
            ->filter(fn ($a) => filled($a->storage_key))
            ->mapWithKeys(fn ($a) => [$a->segment => $a->storage_key]);

        // Optional text-to-speech narration, keyed by segment. audio_key carries a
        // directly-playable (presigned) URL — see the worker's narrator.narrate().
        $audios = $assets
            ->filter(fn ($a) => filled($a->audio_key))
            ->mapWithKeys(fn ($a) => [$a->segment => $a->audio_key]);

        // The benediction is the last text segment generated, so its presence
        // marks the service as fully composed. (Music runs in parallel and is
        // optional — keying "complete" off it would hang if music is skipped.)
        $complete = $assets->firstWhere('segment', 'benediction') !== null;

        return response()->json([
            'status'       => $complete ? 'complete' : $session->status,
            'scheduled_at' => $session->scheduled_at?->toIso8601String(),
            'welcome'      => $welcome,
            'music_asset'  => $music,
            'segments'     => $segments,
            'videos'       => $videos,
            'audios'       => $audios,
            'mood'         => $session->intake?->mood,
        ]);
    }
}
