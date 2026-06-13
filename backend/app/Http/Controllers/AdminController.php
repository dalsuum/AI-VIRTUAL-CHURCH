<?php

namespace App\Http\Controllers;

use App\Jobs\DispatchServiceJob;
use App\Models\CrisisIntercept;
use App\Models\FinancialLedger;
use App\Models\MusicTrack;
use App\Models\ServiceIntake;
use App\Models\ServiceSession;
use App\Models\Setting;
use App\Models\Testimony;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The admin console. Every route here sits behind auth:sanctum + the `admin`
 * middleware, so callers are always an authenticated administrator.
 */
class AdminController extends Controller
{
    /** At-a-glance operational dashboard. */
    public function dashboard(): JsonResponse
    {
        PermissionService::require(request()->user(), 'dashboard.view');
        $guestPattern = 'guest_%@guest.local';

        return response()->json([
            'services' => [
                'total'    => ServiceSession::count(),
                'active'   => ServiceSession::where('status', 'active')->count(),
                'scheduled'=> ServiceSession::whereNotNull('scheduled_at')
                                ->where('scheduled_at', '>', now())->count(),
                'completed'=> ServiceSession::where('status', 'completed')->count(),
                'abandoned'=> ServiceSession::where('status', 'abandoned')->count(),
            ],
            'usage' => [
                // Total worship time across every session, summed from each session's
                // own duration so a few long visits and many short ones both count.
                'hours'      => $this->totalUseHours(),
                'today'      => ServiceSession::whereDate('created_at', today())->count(),
                'active_now' => ServiceSession::where('status', 'active')->count(),
            ],
            'intercepts' => [
                'total' => CrisisIntercept::count(),
                'today' => CrisisIntercept::whereDate('created_at', today())->count(),
            ],
            'offerings' => [
                'count'    => FinancialLedger::count(),
                'total'    => (float) FinancialLedger::sum('amount'),
                'currency' => FinancialLedger::value('currency') ?? 'usd',
                'donors'   => FinancialLedger::whereNotNull('user_id')
                                ->distinct('user_id')->count('user_id'),
            ],
            'testimonies' => [
                'pending'  => Testimony::where('approved', false)->count(),
                'approved' => Testimony::where('approved', true)->count(),
            ],
            'prayer_requests' => [
                'total' => ServiceIntake::whereNotNull('prayer_text')->count(),
                'today' => ServiceIntake::whereNotNull('prayer_text')->whereDate('created_at', today())->count(),
            ],
            'users' => [
                'total'      => User::count(),
                'registered' => User::where('email', 'not like', $guestPattern)->count(),
                'visitors'   => User::where('email', 'like', $guestPattern)->count(),
                'admins'     => User::where('is_admin', true)->count(),
            ],
            'musicgen' => [
                'total' => ServiceSession::where('music_source', 'musicgen')->count(),
                'today' => ServiceSession::where('music_source', 'musicgen')
                            ->whereDate('created_at', today())->count(),
                // Default 1500 tokens ÷ 50 tokens-per-second = 30 s per generation.
                'audio_minutes' => round(
                    ServiceSession::where('music_source', 'musicgen')->count() * 30 / 60, 1
                ),
            ],
        ]);
    }

    /** Cumulative worship hours: each session's started_at→ended_at, summed. */
    private function totalUseHours(): float
    {
        $minutes = ServiceSession::whereNotNull('started_at')
            ->whereNotNull('ended_at')
            ->get(['started_at', 'ended_at'])
            ->reduce(fn ($carry, $s) => $carry + $s->started_at->diffInMinutes($s->ended_at), 0);

        return round($minutes / 60, 1);
    }

    /** Recent services, newest first, with their user, intake (mood + sermon topic), and segment counts. */
    public function services(): JsonResponse
    {
        PermissionService::require(request()->user(), 'services.view');
        $services = ServiceSession::with([
                'user:id,name,email',
                'intake:session_id,mood,scripture_ref',
            ])
            ->withCount('assets')
            ->latest()
            ->limit(50)
            ->get(['id', 'user_id', 'session_token', 'status', 'music_source', 'scheduled_at', 'created_at']);

        return response()->json([
            'services' => $services->map(fn ($s) => [
                'id'            => $s->id,
                'session_token' => $s->session_token,
                'user'          => $s->user ? ['name' => $s->user->name, 'email' => $s->user->email] : null,
                'status'        => $s->status,
                'scheduled_at'  => $s->scheduled_at,
                'created_at'    => $s->created_at,
                'assets_count'  => $s->assets_count,
                'mood'          => $s->intake?->mood,
                'sermon_topic'  => $s->intake?->scripture_ref,
                'music_source'  => $s->music_source,
            ]),
        ]);
    }

    /** Re-run the AI pipeline for a session (e.g. after a worker outage). */
    public function retryService(ServiceSession $service): JsonResponse
    {
        PermissionService::require(request()->user(), 'services.retry');
        abort_if($service->intake === null, 422, 'Session has no intake to regenerate from.');

        // Wipe existing assets so the segment count drops to zero — visible confirmation
        // in the admin list that regeneration is in progress and not a no-op.
        $service->assets()->delete();
        $service->update(['status' => 'active']);
        DispatchServiceJob::dispatch($service->id);

        return response()->json(['ok' => true, 'status' => 'active']);
    }

    public function deleteService(ServiceSession $service): JsonResponse
    {
        PermissionService::require(request()->user(), 'services.delete');
        $service->assets()->delete();
        $service->intake()->delete();
        $service->delete();

        return response()->json(['ok' => true]);
    }

    /** All testimonies (pending first) for moderation. */
    public function testimonies(): JsonResponse
    {
        PermissionService::require(request()->user(), 'testimonies.view');
        $testimonies = Testimony::with('user:id,name')
            ->orderBy('approved')
            ->latest()
            ->limit(100)
            ->get();

        $userIds = $testimonies->pluck('user_id')->filter()->unique()->all();
        $customMoods = $this->allCustomMoodsByUser($userIds);

        return response()->json([
            'testimonies' => $testimonies->map(function ($t) use ($customMoods) {
                $arr = $t->toArray();
                $arr['custom_moods'] = $customMoods->get($t->user_id, []);
                return $arr;
            }),
        ]);
    }

    /** All user-submitted custom mood words per user_id (deduplicated, newest first), keyed by user_id. */
    private function allCustomMoodsByUser(array $userIds): \Illuminate\Support\Collection
    {
        if (empty($userIds)) {
            return collect();
        }

        return ServiceIntake::select('service_intakes.custom_mood', 'service_sessions.user_id')
            ->join('service_sessions', 'service_sessions.id', '=', 'service_intakes.session_id')
            ->whereIn('service_sessions.user_id', $userIds)
            ->whereNotNull('service_intakes.custom_mood')
            ->orderByDesc('service_intakes.id')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('custom_mood')->unique()->values()->all());
    }

    /** Most recent intake per user_id — gives the last selected mood and sermon topic. */
    private function lastIntakeByUser(array $userIds): \Illuminate\Support\Collection
    {
        if (empty($userIds)) {
            return collect();
        }

        return ServiceIntake::select(
                'service_intakes.mood',
                'service_intakes.scripture_ref',
                'service_sessions.user_id'
            )
            ->join('service_sessions', 'service_sessions.id', '=', 'service_intakes.session_id')
            ->whereIn('service_sessions.user_id', $userIds)
            ->orderByDesc('service_intakes.id')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->first());
    }

    public function approveTestimony(Testimony $testimony): JsonResponse
    {
        PermissionService::require(request()->user(), 'testimonies.approve');
        $testimony->update(['approved' => true]);

        return response()->json(['ok' => true]);
    }

    public function deleteTestimony(Testimony $testimony): JsonResponse
    {
        PermissionService::require(request()->user(), 'testimonies.delete');
        $testimony->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Everyone who has ever visited — registered members and anonymous visitors
     * alike — newest first, with how many services they've held and when we last
     * saw them. `is_guest` lets the console badge anonymous visitors.
     */
    public function users(): JsonResponse
    {
        $users = User::withCount('sessions')
            ->withMax('sessions', 'created_at')
            ->latest()
            ->limit(200)
            ->get(['id', 'name', 'email', 'is_admin', 'is_blocked', 'music_source', 'presenter_gender', 'created_at']);

        $userIds = $users->pluck('id')->all();
        $customMoods  = $this->allCustomMoodsByUser($userIds);
        $lastIntakes  = $this->lastIntakeByUser($userIds);

        return response()->json([
            'users' => $users->map(function ($u) use ($customMoods, $lastIntakes) {
                $isGuest = str_ends_with($u->email, '@guest.local');
                $last    = $lastIntakes->get($u->id);

                return [
                    'id'           => $u->id,
                    'name'         => $u->name,
                    'email'        => $isGuest ? null : $u->email,
                    'role'         => $u->role(),
                    'is_admin'     => $u->isAdmin(),
                    'is_blocked'   => $u->is_blocked,
                    'is_guest'     => $isGuest,
                    'music_source'     => $u->music_source,
                    'presenter_gender' => $u->presenter_gender ?? 'female',
                    'visits'       => $u->sessions_count,
                    'last_seen'    => $u->sessions_max_created_at,
                    'created_at'   => $u->created_at,
                    'custom_moods' => $customMoods->get($u->id, []),
                    'last_mood'    => $last?->mood,
                    'last_sermon'  => $last?->scripture_ref,
                ];
            }),
        ]);
    }

    /**
     * Donors, biggest givers first, each with their total giving and most recent
     * testimony and prayer note — the "who is supporting us, and what's on their
     * heart" view the dashboard surfaces.
     */
    public function donors(): JsonResponse
    {
        PermissionService::require(request()->user(), 'donors.view');
        $totals = FinancialLedger::whereNotNull('user_id')
            ->selectRaw('user_id, COUNT(*) as gifts, SUM(amount) as total, MAX(currency) as currency, MAX(created_at) as last_gift')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(100)
            ->get();

        $users = User::whereIn('id', $totals->pluck('user_id'))->get()->keyBy('id');

        $donors = $totals->map(function ($row) use ($users) {
            $user = $users->get($row->user_id);

            $testimony = Testimony::where('user_id', $row->user_id)
                ->latest()->value('content');

            $prayer = ServiceIntake::whereHas('session', fn ($q) => $q->where('user_id', $row->user_id))
                ->whereNotNull('prayer_text')
                ->latest()->value('prayer_text');

            return [
                'user_id'   => $row->user_id,
                'name'      => $user?->name ?? '—',
                'email'     => $user && ! str_ends_with($user->email, '@guest.local') ? $user->email : null,
                'gifts'     => (int) $row->gifts,
                'total'     => (float) $row->total,
                'currency'  => $row->currency ?? 'usd',
                'last_gift' => $row->last_gift,
                'testimony' => $testimony,
                'prayer'    => $prayer,
            ];
        });

        return response()->json(['donors' => $donors]);
    }

    /** All prayer requests submitted through the intake form, newest first. */
    public function prayerRequests(): JsonResponse
    {
        PermissionService::require(request()->user(), 'prayer_requests.view');
        $intakes = ServiceIntake::with('session.user:id,name,email')
            ->whereNotNull('prayer_text')
            ->latest()
            ->limit(200)
            ->get(['id', 'session_id', 'mood', 'custom_mood', 'prayer_text', 'created_at'])
            ->map(function ($intake) {
                $user = $intake->session?->user;
                $isGuest = $user && str_ends_with($user->email, '@guest.local');

                return [
                    'id'          => $intake->id,
                    'mood'        => $intake->mood,
                    'custom_mood' => $intake->custom_mood,
                    'prayer'      => $intake->prayer_text,
                    'submitted'   => $intake->created_at,
                    'user_name'   => $user?->name ?? '—',
                    'user_email'  => ($user && ! $isGuest) ? $user->email : null,
                    'is_guest'    => $isGuest,
                ];
            });

        return response()->json(['prayer_requests' => $intakes]);
    }

    /**
     * Manual CRUD surface for the Suno reuse pool (music_tracks).
     * Admin-only route group; supports filtering by mood/language/search.
     */
    public function musicTracks(Request $request): JsonResponse
    {
        $q = MusicTrack::query()->where('source', 'suno')->orderByDesc('id');

        $mood = trim((string) $request->query('mood', ''));
        if ($mood !== '') {
            $q->where('mood', $mood);
        }

        $language = trim((string) $request->query('language', ''));
        if (in_array($language, ['en', 'my', 'td'], true)) {
            $q->where('language', $language);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $q->where(function ($sub) use ($search) {
                $sub->where('provider_ref', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('storage_key', 'like', "%{$search}%")
                    ->orWhere('mood', 'like', "%{$search}%");
            });
        }

        $limit = (int) $request->query('limit', 100);
        $limit = max(1, min(200, $limit));

        $tracks = $q->limit($limit)->get([
            'id', 'mood', 'language', 'provider_ref', 'storage_key',
            'title', 'lyrics', 'source', 'created_at', 'updated_at',
        ]);

        return response()->json(['tracks' => $tracks]);
    }

    public function createMusicTrack(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mood'         => ['required', 'string', 'max:100'],
            'language'     => ['required', 'string', 'in:en,my,td'],
            'provider_ref' => ['required', 'string', 'max:255', 'unique:music_tracks,provider_ref'],
            'storage_key'  => ['required', 'string', 'max:500'],
            'title'        => ['nullable', 'string', 'max:255'],
            'lyrics'       => ['nullable', 'string'],
            'source'       => ['nullable', 'string', 'in:suno'],
        ]);

        $track = MusicTrack::create([
            'mood'         => trim($data['mood']),
            'language'     => $data['language'],
            'provider_ref' => trim($data['provider_ref']),
            'storage_key'  => trim($data['storage_key']),
            'title'        => isset($data['title']) ? trim((string) $data['title']) : null,
            'lyrics'       => $data['lyrics'] ?? null,
            'source'       => 'suno',
        ]);

        return response()->json(['ok' => true, 'track' => $track], 201);
    }

    public function updateMusicTrack(Request $request, MusicTrack $musicTrack): JsonResponse
    {
        $data = $request->validate([
            'mood'         => ['sometimes', 'string', 'max:100'],
            'language'     => ['sometimes', 'string', 'in:en,my,td'],
            'provider_ref' => ['sometimes', 'string', 'max:255', 'unique:music_tracks,provider_ref,' . $musicTrack->id],
            'storage_key'  => ['sometimes', 'string', 'max:500'],
            'title'        => ['nullable', 'string', 'max:255'],
            'lyrics'       => ['nullable', 'string'],
            'source'       => ['sometimes', 'string', 'in:suno'],
        ]);

        if (array_key_exists('mood', $data)) {
            $musicTrack->mood = trim($data['mood']);
        }
        if (array_key_exists('language', $data)) {
            $musicTrack->language = $data['language'];
        }
        if (array_key_exists('provider_ref', $data)) {
            $musicTrack->provider_ref = trim($data['provider_ref']);
        }
        if (array_key_exists('storage_key', $data)) {
            $musicTrack->storage_key = trim($data['storage_key']);
        }
        if (array_key_exists('title', $data)) {
            $musicTrack->title = $data['title'] !== null ? trim((string) $data['title']) : null;
        }
        if (array_key_exists('lyrics', $data)) {
            $musicTrack->lyrics = $data['lyrics'];
        }
        if (array_key_exists('source', $data)) {
            $musicTrack->source = 'suno';
        }

        $musicTrack->save();

        return response()->json(['ok' => true, 'track' => $musicTrack->fresh()]);
    }

    public function deleteMusicTrack(MusicTrack $musicTrack): JsonResponse
    {
        $musicTrack->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * Stream a report as CSV for download. Supported reports: donations, users,
     * testimonies. Generated on the fly so exports always reflect live data.
     */
    public function export(string $type): StreamedResponse
    {
        [$headers, $rows] = match ($type) {
            'donations'   => $this->donationRows(),
            'users'       => $this->userRows(),
            'testimonies' => $this->testimonyRows(),
            default       => abort(404, 'Unknown report.'),
        };

        $filename = "{$type}-" . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function donationRows(): array
    {
        $rows = FinancialLedger::with('user:id,name,email')
            ->latest()
            ->get()
            ->map(fn ($l) => [
                $l->created_at,
                $this->csvSafe($l->user?->name ?? '—'),
                $this->csvSafe($l->user && ! str_ends_with($l->user->email, '@guest.local') ? $l->user->email : ''),
                $l->amount,
                strtoupper($l->currency),
                $this->csvSafe($l->allocation_type),
            ]);

        return [['Date', 'Donor', 'Email', 'Amount', 'Currency', 'Allocation'], $rows];
    }

    private function userRows(): array
    {
        $rows = User::withCount('sessions')
            ->withMax('sessions', 'created_at')
            ->latest()
            ->get()
            ->map(fn ($u) => [
                $this->csvSafe($u->name),
                $this->csvSafe(str_ends_with($u->email, '@guest.local') ? '(visitor)' : $u->email),
                $u->is_admin ? 'yes' : 'no',
                $u->sessions_count,
                $u->sessions_max_created_at,
                $u->created_at,
            ]);

        return [['Name', 'Email', 'Admin', 'Visits', 'Last seen', 'Joined'], $rows];
    }

    private function testimonyRows(): array
    {
        $rows = Testimony::with('user:id,name')
            ->latest()
            ->get()
            ->map(fn ($t) => [
                $t->created_at,
                $this->csvSafe($t->user?->name ?? '—'),
                $this->csvSafe($t->source),
                $t->approved ? 'approved' : 'pending',
                $this->csvSafe($t->content),
            ]);

        return [['Date', 'By', 'Source', 'Status', 'Content'], $rows];
    }

    private function csvSafe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r\n]/', $value) ? "'" . $value : $value;
    }

    /**
     * Global service settings the admin can tune: the narration voice mode, whether
     * AI-composed songs are reused from the mood pool, and where generated audio is
     * stored (local disk vs S3).
     */
    public function settings(): JsonResponse
    {
        return response()->json($this->settingsPayload());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Per-language narration voice. English supports all providers; Myanmar and
            // Tedim support edge_tts (Microsoft cloud, free) or mms_tts (local MMS, free).
            'narration_mode_en'  => ['sometimes', 'string', 'in:' . implode(',', Setting::NARRATION_MODES)],
            'narration_mode_my'  => ['sometimes', 'string', 'in:edge_tts,mms_tts,off'],
            'narration_mode_td'  => ['sometimes', 'string', 'in:edge_tts,mms_tts,off'],
            // When on, a worshipper new to a mood is served a random song already
            // composed for it instead of generating (and paying for) a fresh one.
            'music_reuse'     => ['sometimes', 'boolean'],
            // Where the worker stores generated audio: local disk or S3.
            'storage_backend' => ['sometimes', 'string', 'in:' . implode(',', Setting::STORAGE_BACKENDS)],
            // The moods offered in the intake form. Free text — a new mood flows
            // through the whole pipeline (LLM tone, music prompt, hymn matching).
            'moods'           => ['sometimes', 'array', 'min:1'],
            'moods.*'         => ['string', 'max:100'],
            // Which music sources worshippers may choose; a non-empty subset.
            'music_sources'   => ['sometimes', 'array', 'min:1'],
            'music_sources.*' => ['string', 'in:' . implode(',', Setting::MUSIC_SOURCES)],
            // Edge TTS voice (used when narration_mode = 'edge_tts').
            'edge_tts_voice'  => ['sometimes', 'string', 'in:' . implode(',', Setting::EDGE_TTS_VOICES)],
            // Voicebox TTS model (used when narration_mode = 'voicebox').
            // Current Docker image exposes Qwen model sizes through POST /generate.
            'voicebox_engine' => ['sometimes', 'string', 'in:qwen,qwen_1_7b'],
            // Whether the "schedule it" option appears in the intake form.
            'scheduling_enabled' => ['sometimes', 'boolean'],
            // The music source pre-selected in the intake form.
            'default_music_source' => ['sometimes', 'string', 'in:' . implode(',', Setting::MUSIC_SOURCES)],
            // Toggle avatar video rendering on/off without touching env vars.
            'avatar_enabled'      => ['sometimes', 'boolean'],
            // Toggle karaoke-style word highlighting in the service player.
            'text_highlight_enabled' => ['sometimes', 'boolean'],
            // Per-language narration toggles. All languages default on.
            // Myanmar and Tedim can use native local MMS-TTS through mms_tts mode.
            'narration_en'        => ['sometimes', 'boolean'],
            'narration_my'        => ['sometimes', 'boolean'],
            'narration_td'        => ['sometimes', 'boolean'],
            // Which service languages appear as tabs in the intake form.
            'lang_en'             => ['sometimes', 'boolean'],
            'lang_my'             => ['sometimes', 'boolean'],
            'lang_td'             => ['sometimes', 'boolean'],
            // Cards shown during the preparation countdown.
            'countdown_content_enabled' => ['sometimes', 'boolean'],
            'countdown_content_source'  => ['sometimes', 'string', 'in:banners,testimonies,online,both,all,off'],
            'countdown_banners'         => ['sometimes', 'array', 'max:12'],
            'countdown_banners.*.text'  => ['required_with:countdown_banners', 'string', 'max:300'],
            'countdown_banners.*.source'=> ['nullable', 'string', 'max:80'],
            // Keywords rejected from YouTube results to enforce Christian-only content.
            'content_filter_keywords'   => ['sometimes', 'array'],
            'content_filter_keywords.*' => ['string', 'max:100'],
        ]);

        foreach (['narration_mode_en', 'narration_mode_my', 'narration_mode_td'] as $key) {
            if (array_key_exists($key, $data)) {
                Setting::set($key, $data[$key]);
            }
        }
        if (array_key_exists('edge_tts_voice', $data)) {
            Setting::set('edge_tts_voice', $data['edge_tts_voice']);
        }
        if (array_key_exists('voicebox_engine', $data)) {
            Setting::set('voicebox_engine', $data['voicebox_engine']);
        }
        if (array_key_exists('music_reuse', $data)) {
            Setting::set('music_reuse', $data['music_reuse'] ? '1' : '0');
        }
        if (array_key_exists('storage_backend', $data)) {
            Setting::set('storage_backend', $data['storage_backend']);
        }
        if (array_key_exists('moods', $data)) {
            // Trim, drop blanks, and de-duplicate while preserving order.
            $moods = array_values(array_unique(array_filter(array_map('trim', $data['moods']))));
            abort_if($moods === [], 422, 'At least one mood is required.');
            Setting::setList('moods', $moods);
        }
        if (array_key_exists('music_sources', $data)) {
            // Store in canonical order so the intake form is consistently ordered.
            $sources = array_values(array_intersect(Setting::MUSIC_SOURCES, $data['music_sources']));
            Setting::setList('music_sources', $sources);
        }
        if (array_key_exists('scheduling_enabled', $data)) {
            Setting::set('scheduling_enabled', $data['scheduling_enabled'] ? '1' : '0');
        }
        if (array_key_exists('default_music_source', $data)) {
            Setting::set('default_music_source', $data['default_music_source']);
        }
        if (array_key_exists('avatar_enabled', $data)) {
            Setting::set('avatar_enabled', $data['avatar_enabled'] ? '1' : '0');
        }
        if (array_key_exists('text_highlight_enabled', $data)) {
            Setting::set('text_highlight_enabled', $data['text_highlight_enabled'] ? '1' : '0');
        }
        foreach (['narration_en', 'narration_my', 'narration_td'] as $key) {
            if (array_key_exists($key, $data)) {
                Setting::set($key, $data[$key] ? '1' : '0');
            }
        }
        foreach (['lang_en', 'lang_my', 'lang_td'] as $key) {
            if (array_key_exists($key, $data)) {
                Setting::set($key, $data[$key] ? '1' : '0');
            }
        }
        if (array_key_exists('countdown_content_enabled', $data)) {
            Setting::set('countdown_content_enabled', $data['countdown_content_enabled'] ? '1' : '0');
        }
        if (array_key_exists('countdown_content_source', $data)) {
            Setting::set('countdown_content_source', $data['countdown_content_source'] === 'off' ? 'off' : $data['countdown_content_source']);
        }
        if (array_key_exists('countdown_banners', $data)) {
            $banners = [];
            foreach ($data['countdown_banners'] as $banner) {
                $text = trim((string) ($banner['text'] ?? ''));
                $source = trim((string) ($banner['source'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $banners[] = ['text' => $text, 'source' => $source];
            }
            abort_if($banners === [], 422, 'At least one countdown banner is required.');
            Setting::setList('countdown_banners', $banners);
        }
        if (array_key_exists('content_filter_keywords', $data)) {
            $keywords = array_values(array_unique(array_filter(array_map('trim', $data['content_filter_keywords']))));
            Setting::setList('content_filter_keywords', $keywords);
        }

        return response()->json(['ok' => true] + $this->settingsPayload());
    }

    /** The settings shape shared by the read and write endpoints. */
    private function settingsPayload(): array
    {
        return [
            'narration_mode_en'  => Setting::narrationMode('en'),
            'narration_mode_my'  => Setting::narrationMode('my'),
            'narration_mode_td'  => Setting::narrationMode('td'),
            'edge_tts_voice'     => Setting::get('edge_tts_voice', 'en-US-AriaNeural'),
            'voicebox_engine'    => Setting::get('voicebox_engine', 'qwen'),
            'music_reuse'        => Setting::get('music_reuse', '1') === '1',
            'storage_backend'    => Setting::get('storage_backend', 'local'),
            'avatar_enabled'     => Setting::get('avatar_enabled', '1') === '1',
            'text_highlight_enabled' => Setting::get('text_highlight_enabled', '1') === '1',
            // Per-language narration: all on by default.
            // Myanmar/Tedim: edge_tts = Microsoft cloud; mms_tts = local MMS-TTS.
            'narration_en'       => Setting::narrationEnabled('en'),
            'narration_my'       => Setting::narrationEnabled('my'),
            'narration_td'       => Setting::narrationEnabled('td'),
            // Which service languages appear in the intake form.
            'lang_en'            => Setting::get('lang_en', '1') === '1',
            'lang_my'            => Setting::get('lang_my', '0') === '1',
            'lang_td'            => Setting::get('lang_td', '0') === '1',
            'moods'                => Setting::moods(),
            'music_sources'        => Setting::enabledMusicSources(),
            'scheduling_enabled'   => Setting::schedulingEnabled(),
            'default_music_source' => Setting::defaultMusicSource(),
            'countdown_content_enabled' => Setting::get('countdown_content_enabled', '1') === '1',
            'countdown_content_source'  => Setting::get('countdown_content_source', 'both'),
            'countdown_banners'         => Setting::countdownBanners(),
            'content_filter_keywords'   => Setting::filterKeywords(),
        ];
    }

    /** Block or unblock a user. Blocked users cannot log in. Guards against self-block. */
    public function blockUser(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot block your own account.'], 422);
        }

        $data = $request->validate(['is_blocked' => ['required', 'boolean']]);
        $user->update(['is_blocked' => $data['is_blocked']]);

        return response()->json(['ok' => true, 'is_blocked' => $user->is_blocked]);
    }

    /** Set the presenter gender (avatar + voice pair) for a specific user. */
    public function updatePresenterGender(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['presenter_gender' => ['required', 'in:male,female']]);
        $user->update($data);

        return response()->json(['ok' => true, 'presenter_gender' => $user->presenter_gender]);
    }

    /** Delete a user and all their data. Guards against self-deletion and last-admin removal. */
    public function deleteUser(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        if ($user->is_admin && User::where('is_admin', true)->count() <= 1) {
            return response()->json(['message' => 'Cannot delete the last admin account.'], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['ok' => true]);
    }

    /** Grant or revoke admin. Guards against an admin removing their own access. */
    public function setAdmin(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['is_admin' => ['required', 'boolean']]);

        if ($user->id === $request->user()->id && ! $data['is_admin']) {
            return response()->json(['message' => 'You cannot revoke your own admin access.'], 422);
        }

        $user->update(['is_admin' => $data['is_admin']]);

        return response()->json(['ok' => true, 'is_admin' => $user->is_admin]);
    }

    /** Assign a role to a user. Guards against demoting yourself from admin. */
    public function assignRole(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', 'string', 'in:' . implode(',', User::ASSIGNABLE_ROLES)],
        ]);

        if ($user->id === $request->user()->id && $data['role'] !== User::ROLE_ADMIN) {
            return response()->json(['message' => 'You cannot remove your own admin role.'], 422);
        }

        $isAdmin = $data['role'] === User::ROLE_ADMIN;
        $user->update(['role' => $data['role'], 'is_admin' => $isAdmin]);

        return response()->json(['ok' => true, 'role' => $data['role']]);
    }

    /**
     * Generate a one-time password-reset token for a user. The admin can share
     * this link with the user out-of-band (copy + paste or email).
     * Token is valid for 24 hours.
     */
    public function forcePasswordReset(User $user): JsonResponse
    {
        $token   = Str::random(64);
        $expires = Carbon::now()->addHours(24);
        $user->update([
            'password_reset_token'      => hash('sha256', $token),
            'password_reset_expires_at' => $expires,
        ]);

        $resetUrl = config('app.url') . '/#reset?token=' . $token;

        return response()->json([
            'ok'        => true,
            'reset_url' => $resetUrl,
            'expires_at'=> $expires->toIso8601String(),
        ]);
    }

    /** Return the current permissions matrix for all configurable roles. */
    public function getPermissions(): JsonResponse
    {
        return response()->json([
            'permissions' => PermissionService::all(),
            'available'   => PermissionService::PERMISSIONS,
            'roles'       => PermissionService::CONFIGURABLE_ROLES,
        ]);
    }

    /** Persist a new permissions matrix. Accepts partial updates per role. */
    public function updatePermissions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'permissions'             => ['required', 'array'],
            'permissions.moderator'   => ['sometimes', 'array'],
            'permissions.moderator.*' => ['string', 'in:' . implode(',', PermissionService::PERMISSIONS)],
            'permissions.presenter'   => ['sometimes', 'array'],
            'permissions.presenter.*' => ['string', 'in:' . implode(',', PermissionService::PERMISSIONS)],
        ]);

        PermissionService::save($data['permissions']);

        return response()->json(['ok' => true, 'permissions' => PermissionService::all()]);
    }

    /**
     * Create a new user account directly from the admin console.
     * Returns the user and (optionally) a password-reset link when no
     * password is supplied, so the admin can share a first-login link.
     */
    public function createUser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'role'     => ['required', 'string', 'in:' . implode(',', User::ASSIGNABLE_ROLES)],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $hasPassword = ! empty($data['password']);
        $user = User::create([
            'name'          => $data['name'],
            'email'         => $data['email'],
            'password'      => \Illuminate\Support\Facades\Hash::make(
                $hasPassword ? $data['password'] : Str::random(32)
            ),
            'role'          => $data['role'],
            'is_admin'      => $data['role'] === User::ROLE_ADMIN,
            'name_provided' => true,
            'timezone'      => 'UTC',
            'music_source'  => 'hymn_sung',
        ]);

        $result = ['ok' => true, 'user_id' => $user->id, 'reset_url' => null];

        // When no password was given, generate a first-login reset link so the
        // admin can share it with the new user — they set their own password.
        if (! $hasPassword) {
            $token   = Str::random(64);
            $expires = Carbon::now()->addHours(48);
            $user->update([
                'password_reset_token'      => hash('sha256', $token),
                'password_reset_expires_at' => $expires,
            ]);
            $result['reset_url']  = config('app.url') . '/#reset?token=' . $token;
            $result['expires_at'] = $expires->toIso8601String();
        }

        return response()->json($result, 201);
    }
}
