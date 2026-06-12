<?php

namespace App\Http\Controllers;

use App\Jobs\DispatchServiceJob;
use App\Models\ServiceIntake;
use App\Models\ServiceSession;
use App\Models\Setting;
use App\Notifications\ServiceScheduledNotification;
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
            'mood'          => ['required', 'string', 'max:100'],
            // Service language ('en' | 'my' | 'td'), chosen on the intake form's
            // language tab. Locked per session like music_source; the worker keys
            // the LLM output language, Bible translation, hymn library, and TTS
            // voice off it.
            'language'      => ['nullable', 'string', 'in:en,my,td'],
            // User-supplied single-word feeling, stored for admin review only.
            'custom_mood'   => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z]+$/'],
            'prayer_text'   => ['nullable', 'string', 'max:5000'],
            // Optional: hold the service until a chosen future moment.
            'scheduled_at'  => ['nullable', 'date', 'after:now'],
            // Contact email supplied at scheduling time; used to update a guest
            // account that still has a synthetic @guest.local address.
            'contact_email' => ['nullable', 'email', 'max:255'],
        ]);

        // Lock the service language now, alongside the already-locked music source.
        $session->update(['language' => $data['language'] ?? 'en']);

        // A future time is only honoured while scheduling is enabled; otherwise the
        // service begins now (the UI hides the option, this guards direct calls).
        if (! empty($data['scheduled_at']) && ! Setting::schedulingEnabled()) {
            unset($data['scheduled_at']);
        }

        $user = $request->user();

        // A scheduled service requires a notification address. Accept either the
        // user's stored real email or a contact_email supplied with this request.
        // We store contact_email on the session so it survives regardless of whether
        // the guest account's email field could be updated.
        $contactEmail = $data['contact_email'] ?? null;
        $notifyEmail  = $contactEmail
            ?: (str_ends_with($user->email, '@guest.local') ? null : $user->email);

        if (! empty($data['scheduled_at']) && ! $notifyEmail) {
            return response()->json([
                'message' => 'Please provide your email so we can send you a reminder when your service begins.',
            ], 422);
        }

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
            [
                'mood'        => $data['mood'],
                'custom_mood' => $data['custom_mood'] ?? null,
                'prayer_text' => $data['prayer_text'] ?? null,
            ],
        );

        // Scheduled for later: hold it for the scheduler (see DispatchDueServices).
        // Otherwise dispatch now and fan out to the Python workers immediately.
        if (! empty($data['scheduled_at'])) {
            $session->update([
                'status'        => 'scheduled',
                'scheduled_at'  => $data['scheduled_at'],
                'contact_email' => $contactEmail,
            ]);

            // Send an immediate booking confirmation to the notification address.
            if ($notifyEmail) {
                \Illuminate\Support\Facades\Notification::route('mail', $notifyEmail)
                    ->notify(new ServiceScheduledNotification($session, $user->name, $notifyEmail));
            }

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

    /**
     * Public endpoint used by email links. Accepts the 64-char session token,
     * re-issues a Sanctum token for the session owner, and returns both so the
     * SPA can restore its auth state and jump straight into the service — even
     * on a different device or after browser storage was cleared.
     *
     * The session token is a 64-char random string and acts as a single-use
     * credential here; only the person who received the email can guess it.
     */
    public function resume(string $token): JsonResponse
    {
        $session = ServiceSession::where('session_token', $token)->firstOrFail();
        $user    = $session->user;

        $authToken = $user->createToken('api')->plainTextToken;

        return response()->json([
            'auth_token'    => $authToken,
            'session_token' => $session->session_token,
            'status'        => $session->status,
            'scheduled_at'  => $session->scheduled_at?->toIso8601String(),
        ]);
    }

    /** A user's own recent service history — up to 10 sessions, newest first. */
    public function myServices(Request $request): JsonResponse
    {
        $sessions = ServiceSession::with('intake:session_id,mood,custom_mood,scripture_ref')
            ->where('user_id', $request->user()->id)
            ->whereNotIn('status', ['initializing', 'abandoned'])
            ->latest()
            ->limit(10)
            ->get(['id', 'session_token', 'status', 'created_at', 'scheduled_at']);

        return response()->json([
            'sessions' => $sessions->map(fn ($s) => [
                'session_token' => $s->session_token,
                'status'        => $s->status,
                'mood'          => $s->intake?->mood,
                'custom_mood'   => $s->intake?->custom_mood,
                'sermon_topic'  => $s->intake?->scripture_ref,
                'date'          => $s->created_at,
                'scheduled_at'  => $s->scheduled_at,
            ]),
        ]);
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
        // { asset_type, provider_ref, url, title } contract the Vue MusicPlayer
        // expects. The worker stores the track's title (hymn name + author, or
        // "Worship (mood)" for Suno) in text_payload so the player can caption it.
        $worship = $assets->firstWhere('segment', 'worship');
        $music = $worship ? [
            'asset_type'   => $worship->asset_type,
            'provider_ref' => $worship->provider_ref,   // YouTube video id
            'url'          => $worship->storage_key,     // stored-audio key (presign later)
            'title'        => $worship->text_payload,    // track caption
            'lyrics'       => $worship->lyrics,          // public-domain hymn verses (hymn sources)
        ] : null;

        // Spoken/text segments, keyed by segment name for the client to render.
        // Keyed off text_payload (not asset_type) so the words survive even after a
        // segment is enriched with an avatar video, which flips asset_type to 'video'.
        // The "welcome" greeting (countdown screen) and the music segments — whose
        // text_payload is the track title, surfaced via music_asset above — are not
        // spoken service segments, so they're excluded here.
        $musicSegments = ['welcome', 'worship', 'closing_hymn'];
        $segments = $assets
            ->filter(fn ($a) => filled($a->text_payload)
                && $a->asset_type !== 'youtube'
                && ! in_array($a->segment, $musicSegments, true))
            ->mapWithKeys(fn ($a) => [$a->segment => $a->text_payload]);

        // Embedded video segments — e.g. the preaching message in YouTube mode,
        // which is a sourced sermon clip rather than AI-written text. Keyed by
        // segment; the player embeds provider_ref (the video id) and captions it
        // with the title (carried in text_payload). Music segments are surfaced via
        // music_asset above, so they're excluded here.
        $embeds = $assets
            ->where('asset_type', 'youtube')
            ->filter(fn ($a) => ! in_array($a->segment, $musicSegments, true))
            ->mapWithKeys(fn ($a) => [$a->segment => [
                'provider_ref' => $a->provider_ref,
                'title'        => $a->text_payload,
            ]]);

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
            'embeds'       => $embeds,
            'videos'       => $videos,
            'audios'       => $audios,
            // How the player should voice spoken segments: 'openai'/'kokoro' (play the
            // audio above), 'browser' (read via speechSynthesis), or 'off' (text only).
            'narration_mode' => Setting::get('narration_mode', 'browser'),
            'mood'         => $session->intake?->mood,
        ]);
    }
}
