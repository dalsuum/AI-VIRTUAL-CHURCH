<?php

namespace App\Http\Controllers;

use App\Jobs\DispatchServiceJob;
use App\Models\ServiceIntake;
use App\Models\ServiceSession;
use App\Models\Setting;
use App\Notifications\ServiceScheduledNotification;
use App\Services\CrisisInterceptService;
use App\Services\GuestUsageService;
use App\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class ServiceController extends Controller
{
    private const NARRATED_SEGMENTS = ['opening_prayer', 'scripture', 'sermon', 'benediction'];

    public function __construct(
        private CrisisInterceptService $crisis,
        private TokenService $tokens,
        private GuestUsageService $guests,
    ) {}

    /**
     * Charge one worship-service generation. Members/premium spend a token; guests
     * record their single free use. Idempotent per session via the `service:{id}`
     * reference + the caller's already-active guard, so a retried intake never
     * double-charges. Eligibility was already verified by the route middleware.
     */
    private function chargeService(Request $request, ServiceSession $session): void
    {
        $user = $request->user();

        if ($user->isGuestAccount()) {
            $this->guests->record($request, 'service');

            return;
        }

        // Don't charge twice if this session was already paid for (e.g. scheduled then
        // dispatched, or a duplicate intake).
        $already = $user->tokenLedger()
            ->where('reference', "service:{$session->id}")
            ->whereIn('type', ['spend', 'reservation'])
            ->exists();
        if (! $already) {
            $this->tokens->spend($user, 'service', "service:{$session->id}");
        }
    }

    /** Create a session. The media source is locked from the user's preference now. */
    public function start(Request $request): JsonResponse
    {
        $user = $request->user();

        $session = ServiceSession::create([
            'user_id'          => $user->id,
            'session_token'    => Str::random(64),
            'status'           => 'initializing',
            'music_source'     => $user->music_source,
            'presenter_gender' => $user->presenter_gender ?? 'female',
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

            // Scheduling commits to a generation, so charge now.
            $this->chargeService($request, $session);

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

        // Guard: if this session already triggered the pipeline, don't burn a second GPU job.
        if (in_array($session->status, ['active', 'processing', 'complete'])) {
            return response()->json([
                'intercepted'   => false,
                'session_token' => $session->session_token,
                'intake_id'     => $intake->id,
                'status'        => $session->status,
            ], 202);
        }

        $this->chargeService($request, $session);

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
     * establishes an HttpOnly session cookie for the session owner, and returns
     * service metadata so the SPA can jump straight into the service — even on
     * a different device or after browser storage was cleared.
     *
     * The session token is a 64-char random string and acts as the credential
     * here; only the person who received the email can guess it.
     */
    public function resume(Request $request, string $token): JsonResponse
    {
        if (! $request->hasSession()) {
            return response()->json([
                'message' => 'Session cookies are required to resume this service.',
            ], 400);
        }

        $session = ServiceSession::where('session_token', $token)->firstOrFail();
        $user    = $session->user;

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
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
            'timings'      => $worship->timings,         // optional LRC line timings paired with lyrics
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

        $language = $session->language ?? 'en';
        $narrationEnabled = Setting::narrationEnabled($language);
        $narrationMode = Setting::narrationMode($language);

        // Optional text-to-speech narration, keyed by segment. audio_key carries a
        // directly-playable (presigned) URL — see the worker's narrator.narrate().
        $audios = $assets
            ->filter(fn ($a) => filled($a->audio_key))
            ->mapWithKeys(fn ($a) => [$a->segment => $a->audio_key]);

        // The benediction is the last text segment generated, so its presence
        // marks the service as fully composed. (Music runs in parallel and is
        // optional — keying "complete" off it would hang if music is skipped.)
        $complete = $assets->firstWhere('segment', 'benediction') !== null;
        if ($complete) {
            $this->queueMissingNarration($session, $assets, $language, $narrationMode, $narrationEnabled);
        }

        return response()->json([
            'status'       => $complete ? 'complete' : $session->status,
            'scheduled_at' => $session->scheduled_at?->toIso8601String(),
            'welcome'      => $welcome,
            'music_asset'  => $music,
            'music_source' => $session->music_source,
            'segments'     => $segments,
            'embeds'       => $embeds,
            'videos'       => $videos,
            'audios'       => $audios,
            // How the player should voice spoken segments. Read per-language so the
            // player knows whether to expect server audio ('edge_tts'/'mms_tts'/
            // 'openai'/'kokoro') or fall back to browser speech / silence.
            'narration_mode' => $narrationMode,
            'narration_enabled' => $narrationEnabled,
            // Whether avatar (talking-head) videos are being produced for this service.
            // The client uses this to keep polling for late-arriving avatar videos —
            // the benediction renders last and would otherwise land after the player
            // stops polling, leaving its segment without video. Mirrors how the worker
            // decides to render (DispatchServiceJob): either engine being on means yes.
            'avatar_enabled' => Setting::get('avatar_enabled', '1') === '1'
                || Setting::get('local_avatar_enabled', '0') === '1',
            'text_highlight_enabled' => Setting::get('text_highlight_enabled', '1') === '1',
            'ad_slot_enabled' => Setting::get('ad_slot_enabled', '0') === '1',
            'ad_slot_html'    => Setting::get('ad_slot_html', '') ?: '',
            'language'    => $language,
            'mood'         => $session->intake?->mood,
        ]);
    }

    private function queueMissingNarration($session, $assets, string $language, string $mode, bool $enabled): void
    {
        try {
            if (! $enabled || ! in_array($mode, Setting::SERVER_NARRATION_MODES, true)) {
                return;
            }

            $missing = [];
            foreach (self::NARRATED_SEGMENTS as $segment) {
                $asset = $assets->firstWhere('segment', $segment);
                if (! $asset || $asset->asset_type === 'youtube' || blank($asset->text_payload) || filled($asset->audio_key)) {
                    continue;
                }
                if ($asset->ready_at && $asset->ready_at->gt(now()->subSeconds(90))) {
                    continue;
                }

                $lockKey = "ai:narration-repair:{$session->id}:{$segment}";
                if (! Redis::setnx($lockKey, '1')) {
                    continue;
                }
                Redis::expire($lockKey, 15 * 60);

                $missing[] = [
                    'segment' => $segment,
                    'text'    => $asset->text_payload,
                ];
            }

            if ($missing === []) {
                return;
            }

            Redis::rpush('ai:narration-repair', json_encode([
                'session_id'        => $session->id,
                'session_token'     => $session->session_token,
                'language'          => $language,
                'narration_mode'    => $mode,
                'narration_enabled' => true,
                'voicebox_engine'   => Setting::get('voicebox_engine', 'qwen'),
                'presenter_gender'  => $session->presenter_gender ?? 'female',
                'storage_backend'   => Setting::get('storage_backend'),
                'segments'          => $missing,
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
