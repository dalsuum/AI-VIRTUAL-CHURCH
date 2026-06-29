<?php

namespace App\Http\Controllers;

use App\Jobs\DispatchServiceJob;
use App\Models\ChatSession;
use App\Models\CrisisIntercept;
use App\Models\FinancialLedger;
use App\Models\MusicTrack;
use App\Models\ServiceIntake;
use App\Models\ServiceSession;
use App\Models\ServiceSessionMeta;
use App\Models\Setting;
use App\Models\Testimony;
use App\Models\User;
use App\Http\Requests\UpdateSettingsRequest;
use App\Enums\LedgerType;
use App\Services\BibleBgMusicLibrary;
use App\Services\PermissionService;
use App\Services\TokenService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
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
                'admins'     => User::where('role', User::ROLE_ADMIN)->count(),
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
            // Removable special-day features (MV + Live Sticker): surface their
            // visitor render traffic, but only while the admin has them enabled.
            'features' => $this->featureUsage(),
        ]);
    }

    /**
     * Per-feature visitor render counts for the dashboard. Each feature stores
     * its own enable flag + usage counter in a plain config.json (no DB); we read
     * them here and report only the features that are currently enabled.
     */
    private function featureUsage(): array
    {
        $features = [
            'special_day' => 'fathersday/config.json', // Special Day Music Video
            'live_sticker'=> 'stickers/config.json',    // Live Sticker maker
        ];

        $out = [];
        foreach ($features as $key => $rel) {
            $c = Storage::exists($rel)
                ? json_decode((string) Storage::get($rel), true)
                : null;
            if (! is_array($c) || empty($c['enabled'])) {
                continue;
            }
            $u = is_array($c['usage'] ?? null) ? $c['usage'] : [];
            $today = (($u['date'] ?? null) === today()->toDateString())
                ? (int) ($u['today'] ?? 0) : 0;
            $out[$key] = [
                'enabled' => true,
                'total'   => (int) ($u['total'] ?? 0),
                'today'   => $today,
            ];
        }

        return $out;
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

    /** Create a fresh, scoped service resume link for staff-assisted sharing. */
    public function serviceResumeLink(ServiceSession $service): JsonResponse
    {
        PermissionService::require(request()->user(), 'services.view');
        abort_unless($service->user && $service->user->isActive() && ! $service->user->is_blocked, 403);

        $token = $service->issueResumeToken();
        $url = rtrim((string) config('church.frontend_url'), '/') . '?session=' . $token;

        return response()->json([
            'url'        => $url,
            'expires_at' => $service->fresh()->resume_token_expires_at?->toIso8601String(),
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
        $this->purgeService($service);

        return response()->json(['ok' => true]);
    }

    /**
     * Hard-delete a service and the worshipper's linked unified-history entry.
     * The history spine (`chat_sessions`) links via `service_sessions_meta`, whose
     * `service_session_id` FK is nullOnDelete — so dropping only the ServiceSession
     * would leave an orphaned "Church Service" row in the user's profile history.
     * Admin deletion is final, so we forceDelete the spine (cascading meta + any
     * messages/tags) instead of soft-deleting it.
     */
    private function purgeService(ServiceSession $service): void
    {
        $chatIds = ServiceSessionMeta::where('service_session_id', $service->id)->pluck('chat_session_id');
        ChatSession::withTrashed()->whereIn('id', $chatIds)->forceDelete();
        $service->assets()->delete();
        $service->intake()->delete();
        $service->delete();
    }

    /** Delete many services (and their assets/intakes) atomically. */
    public function bulkDeleteServices(Request $request): JsonResponse
    {
        PermissionService::require($request->user(), 'services.delete');

        $data = $request->validate([
            'service_ids'   => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer'],
        ]);

        $deleted = 0;

        DB::transaction(function () use ($data, &$deleted) {
            $services = ServiceSession::whereIn('id', $data['service_ids'])->get();

            foreach ($services as $service) {
                $this->purgeService($service);
                $deleted++;
            }
        });

        return response()->json(['ok' => true, 'deleted' => $deleted]);
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
        PermissionService::require(request()->user(), 'users.view');
        $users = User::withCount('sessions')
            ->withMax('sessions', 'created_at')
            ->latest()
            ->limit(200)
            ->get(['id', 'name', 'email', 'role', 'is_admin', 'is_blocked', 'music_source', 'presenter_gender', 'created_at', 'subscription_plan', 'token_balance']);

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
                    'plan'         => $isGuest ? 'guest' : $u->plan()->value,
                    'token_balance'=> $isGuest ? null : (int) $u->token_balance,
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
        PermissionService::require($request->user(), 'music_pool.view');
        $q = MusicTrack::query()
            ->where(function ($query) {
                $query->whereIn('source', ['suno', 'musicgen', 'local_ai'])->orWhere('provider_ref', 'like', 'musicgen:%');
            })->orderByDesc('id');

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
            'source'       => ['nullable', 'string', 'in:suno,musicgen,local_ai'],
        ]);

        $track = MusicTrack::create([
            'mood'         => trim($data['mood']),
            'language'     => $data['language'],
            'provider_ref' => trim($data['provider_ref']),
            'storage_key'  => trim($data['storage_key']),
            'title'        => isset($data['title']) ? trim((string) $data['title']) : null,
            'lyrics'       => $data['lyrics'] ?? null,
            'source'       => $data['source'] ?? (str_starts_with(trim($data['provider_ref']), 'musicgen:') ? 'musicgen' : 'suno'),
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
            'source'       => ['sometimes', 'string', 'in:suno,musicgen'],
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
            $musicTrack->source = $data['source'];
        }

        $musicTrack->save();

        return response()->json(['ok' => true, 'track' => $musicTrack->fresh()]);
    }

    public function deleteMusicTrack(MusicTrack $musicTrack): JsonResponse
    {
        $musicTrack->delete();
        return response()->json(['ok' => true]);
    }

    // ── Special Sundays (observance-driven sermon/worship bias) ──────────────

    /**
     * Monitor + manage payload for the Special Sundays console tab:
     *   - current:     the observance active right now (if any)
     *   - observances: every catalog row + its next 3 resolved dates (controls)
     *   - calendar:    every observance resolved for this year + next, date-sorted
     *   - audit:       recent services that were generated inside a window, with
     *                  the observance that biased them (resolved retroactively, so
     *                  no extra columns are needed on service_sessions)
     */
    public function specialSundays(\App\Services\SpecialSundayResolver $resolver): JsonResponse
    {
        PermissionService::require(request()->user(), 'special_sundays.view');

        $now  = \Carbon\CarbonImmutable::now();
        $rows = \App\Models\SpecialSunday::with(['sermons', 'songs'])
            ->orderByDesc('priority')->orderBy('key')->get();

        $observances = $rows->map(function (\App\Models\SpecialSunday $s) {
            return [
                'id'            => $s->id,
                'key'           => $s->key,
                'rule_type'     => $s->rule_type,
                'rule'          => $s->rule,
                'titles'        => $s->titles,
                'briefs'        => $s->briefs,
                'sermon_tags'   => $s->sermon_tags,
                'music_moods'   => $s->music_moods,
                'content_modes' => $s->content_modes ?? new \stdClass(),
                'region'        => $s->region,
                'priority'      => $s->priority,
                'active'        => $s->active,
                'next_dates'    => array_map(fn ($d) => $d->toDateString(), $s->nextOccurrences(3)),
                'sermons'       => $s->sermons->map(fn ($m) => [
                    'id' => $m->id, 'language' => $m->language, 'title' => $m->title,
                    'body' => $m->body, 'mood' => $m->mood, 'region' => $m->region,
                    'priority' => $m->priority, 'active' => $m->active,
                ])->values(),
                'songs'         => $s->songs->map(fn ($m) => [
                    'id' => $m->id, 'language' => $m->language, 'title' => $m->title,
                    'source_type' => $m->source_type, 'source_ref' => $m->source_ref,
                    'lyrics' => $m->lyrics, 'mood' => $m->mood, 'region' => $m->region,
                    'priority' => $m->priority, 'active' => $m->active,
                ])->values(),
            ];
        })->values();

        // Year calendar: this year + next, only active rows, sorted by date.
        $calendar = [];
        foreach ($rows->where('active', true) as $s) {
            foreach ([$now->year, $now->year + 1] as $year) {
                $occ = $s->occurrenceFor($year);
                if ($occ === null) {
                    continue;
                }
                $calendar[] = [
                    'key'      => $s->key,
                    'title'    => $s->titles['en'] ?? $s->key,
                    'date'     => $occ->toDateString(),
                    'priority' => $s->priority,
                    'is_past'  => $occ->lessThan($now->startOfDay()),
                ];
            }
        }
        usort($calendar, fn ($a, $b) => strcmp($a['date'], $b['date']));

        $active = $resolver->activeFor($now);

        return response()->json([
            'current'     => $active === null ? null : [
                'key'   => $active['special']->key,
                'title' => $active['special']->titles['en'] ?? $active['special']->key,
                'date'  => $active['sunday']->toDateString(),
            ],
            'observances' => $observances,
            'calendar'    => $calendar,
            'audit'       => $this->specialSundayAudit($resolver),
            'rule_types'  => ['nth_weekday', 'easter_offset', 'fixed'],
        ]);
    }

    /**
     * Recent services whose creation time fell inside an observance window, with
     * the observance that biased them. Resolved retroactively from created_at so
     * we never had to persist the bias on the row.
     */
    private function specialSundayAudit(\App\Services\SpecialSundayResolver $resolver, int $limit = 40): array
    {
        $sessions = ServiceSession::with('intake:session_id,mood')
            ->where('created_at', '>=', now()->subDays(120))
            ->orderByDesc('id')
            ->limit(300)
            ->get(['id', 'language', 'status', 'created_at']);

        $audit = [];
        foreach ($sessions as $session) {
            $active = $resolver->activeFor(\Carbon\CarbonImmutable::instance($session->created_at));
            if ($active === null) {
                continue;
            }
            $audit[] = [
                'session_id' => $session->id,
                'created_at' => $session->created_at->toDateTimeString(),
                'language'   => $session->language,
                'status'     => $session->status,
                'mood'       => $session->intake?->mood,
                'observance' => $active['special']->key,
                'title'      => $active['special']->titles['en'] ?? $active['special']->key,
            ];
            if (count($audit) >= $limit) {
                break;
            }
        }

        return $audit;
    }

    /**
     * Preview what a service WOULD play for this observance at a given language +
     * mood, without dispatching anything. Shows the resolved mode per segment and
     * the concrete manual pick (or, for 'auto', the bias tags/moods that steer the
     * AI). Lets an admin confirm a manual selection before Sunday.
     */
    public function previewSpecialSunday(Request $request, \App\Models\SpecialSunday $specialSunday): JsonResponse
    {
        PermissionService::require($request->user(), 'special_sundays.view');

        $language = in_array($request->query('language'), ['en', 'my', 'td'], true)
            ? $request->query('language')
            : 'en';
        $mood = mb_substr(trim((string) $request->query('mood', '')), 0, 100);

        $content = $specialSunday->resolveContent($language, $mood !== '' ? $mood : null);

        return response()->json([
            'language'    => $language,
            'mood'        => $mood,
            'title'       => $specialSunday->titles[$language] ?? $specialSunday->titles['en'] ?? $specialSunday->key,
            'sermon'      => $content['sermon'],
            'music'       => $content['music'],
            // For the 'auto' case, the bias that would steer the AI selection.
            'sermon_tags' => $specialSunday->sermon_tags,
            'music_moods' => $specialSunday->music_moods,
        ]);
    }

    public function createSpecialSunday(Request $request): JsonResponse
    {
        PermissionService::require($request->user(), 'special_sundays.manage');

        $data = $this->validateSpecialSunday($request, null);
        $special = \App\Models\SpecialSunday::create($data);

        return response()->json(['ok' => true, 'observance' => $special], 201);
    }

    public function updateSpecialSunday(Request $request, \App\Models\SpecialSunday $specialSunday): JsonResponse
    {
        PermissionService::require($request->user(), 'special_sundays.manage');

        $data = $this->validateSpecialSunday($request, $specialSunday, partial: true);
        $specialSunday->update($data);

        return response()->json(['ok' => true, 'observance' => $specialSunday->fresh()]);
    }

    public function deleteSpecialSunday(Request $request, \App\Models\SpecialSunday $specialSunday): JsonResponse
    {
        PermissionService::require($request->user(), 'special_sundays.manage');

        $specialSunday->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Validate a special-Sunday create/update. The `rule` shape is checked
     * against `rule_type`, and my/td title+brief text is NFC-normalized to
     * canonical Myanmar Unicode (the invariant the rest of the stack assumes).
     */
    private function validateSpecialSunday(Request $request, ?\App\Models\SpecialSunday $existing, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        $rules = [
            'key'            => [$req, 'string', 'max:80', 'regex:/^[a-z0-9_]+$/',
                                 'unique:special_sundays,key' . ($existing ? ',' . $existing->id : '')],
            'rule_type'      => [$req, 'string', 'in:nth_weekday,easter_offset,fixed'],
            'rule'           => [$req, 'array'],
            'titles'         => [$req, 'array'],
            'titles.en'      => [$req, 'string', 'max:120'],
            'titles.my'      => ['nullable', 'string', 'max:120'],
            'titles.td'      => ['nullable', 'string', 'max:120'],
            'briefs'         => [$req, 'array'],
            'briefs.en'      => [$req, 'string', 'max:500'],
            'briefs.my'      => ['nullable', 'string', 'max:500'],
            'briefs.td'      => ['nullable', 'string', 'max:500'],
            'sermon_tags'    => ['sometimes', 'array', 'max:20'],
            'sermon_tags.*'  => ['string', 'max:60'],
            'music_moods'    => ['sometimes', 'array', 'max:20'],
            'music_moods.*'  => ['string', 'max:60'],
            'region'         => ['nullable', 'string', 'max:60'],
            'priority'       => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'active'         => ['sometimes', 'boolean'],
            // Per-language delivery mode: { sermon: {en,my,td}, music: {en,my,td} }
            'content_modes'              => ['sometimes', 'nullable', 'array'],
            'content_modes.sermon'       => ['sometimes', 'array'],
            'content_modes.sermon.*'     => ['in:auto,manual'],
            'content_modes.music'        => ['sometimes', 'array'],
            'content_modes.music.*'      => ['in:auto,manual'],
        ];

        $data = $request->validate($rules);

        // Cross-validate the rule body against the selected rule_type.
        $ruleType = $data['rule_type'] ?? $existing?->rule_type;
        if (array_key_exists('rule', $data)) {
            $this->assertRuleShape($ruleType, $data['rule']);
        }

        foreach (['titles', 'briefs'] as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = $this->normalizeLangMap($data[$field]);
            }
        }

        return $data;
    }

    /** Abort 422 if $rule does not carry the keys the $ruleType needs. */
    private function assertRuleShape(?string $ruleType, array $rule): void
    {
        $fail = fn (string $msg) => abort(422, $msg);

        switch ($ruleType) {
            case 'nth_weekday':
                $m = (int) ($rule['month'] ?? 0);
                $w = (int) ($rule['weekday'] ?? -1);
                $n = (int) ($rule['nth'] ?? 0);
                if ($m < 1 || $m > 12)  $fail('nth_weekday rule needs month 1–12.');
                if ($w < 0 || $w > 6)   $fail('nth_weekday rule needs weekday 0 (Sun)–6 (Sat).');
                if ($n === 0 || $n < -5 || $n > 5) $fail('nth_weekday rule needs nth in -5..5 (negative counts from the end).');
                break;
            case 'easter_offset':
                if (! array_key_exists('offset', $rule) || ! is_numeric($rule['offset'])) {
                    $fail('easter_offset rule needs a numeric offset (days from Easter Sunday).');
                }
                break;
            case 'fixed':
                $m = (int) ($rule['month'] ?? 0);
                $d = (int) ($rule['day'] ?? 0);
                if ($m < 1 || $m > 12) $fail('fixed rule needs month 1–12.');
                if ($d < 1 || $d > 31) $fail('fixed rule needs day 1–31.');
                break;
            default:
                $fail('Unknown rule_type.');
        }
    }

    /** NFC-normalize each {en,my,td} string so Myanmar text is canonical Unicode. */
    private function normalizeLangMap(array $map): array
    {
        foreach ($map as $lang => $text) {
            if (is_string($text) && class_exists(\Normalizer::class)) {
                $map[$lang] = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
            }
        }

        return $map;
    }

    /** NFC-normalize a single string (Myanmar Unicode invariant). */
    private function normalizeText(?string $text): ?string
    {
        if ($text === null || ! class_exists(\Normalizer::class)) {
            return $text;
        }

        return \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
    }

    // ── Curated sermons attached to a special Sunday ─────────────────────────

    public function createSpecialSermon(Request $request, \App\Models\SpecialSunday $specialSunday): JsonResponse
    {
        PermissionService::require($request->user(), 'special_sundays.manage');

        $data = $this->validateSermon($request);
        $data['special_sunday_id'] = $specialSunday->id;
        $sermon = \App\Models\SpecialSermon::create($data);

        return response()->json(['ok' => true, 'sermon' => $sermon], 201);
    }

    public function updateSpecialSermon(Request $request, \App\Models\SpecialSermon $specialSermon): JsonResponse
    {
        PermissionService::require($request->user(), 'special_sundays.manage');

        $specialSermon->update($this->validateSermon($request, partial: true));

        return response()->json(['ok' => true, 'sermon' => $specialSermon->fresh()]);
    }

    public function deleteSpecialSermon(Request $request, \App\Models\SpecialSermon $specialSermon): JsonResponse
    {
        PermissionService::require($request->user(), 'special_sundays.manage');
        $specialSermon->delete();

        return response()->json(['ok' => true]);
    }

    private function validateSermon(Request $request, bool $partial = false): array
    {
        $req  = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'language' => [$req, 'string', 'in:en,my,td'],
            'title'    => [$req, 'string', 'max:200'],
            'body'     => [$req, 'string', 'max:20000'],
            'mood'     => ['nullable', 'string', 'max:60'],
            'region'   => ['nullable', 'string', 'max:60'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'active'   => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('title', $data)) $data['title'] = $this->normalizeText($data['title']);
        if (array_key_exists('body', $data))  $data['body']  = $this->normalizeText($data['body']);

        return $data;
    }

    // ── Curated songs attached to a special Sunday ───────────────────────────

    public function createSpecialSong(Request $request, \App\Models\SpecialSunday $specialSunday): JsonResponse
    {
        PermissionService::require($request->user(), 'special_sundays.manage');

        $data = $this->validateSong($request);
        $data['special_sunday_id'] = $specialSunday->id;
        $song = \App\Models\SpecialSong::create($data);

        return response()->json(['ok' => true, 'song' => $song], 201);
    }

    public function updateSpecialSong(Request $request, \App\Models\SpecialSong $specialSong): JsonResponse
    {
        PermissionService::require($request->user(), 'special_sundays.manage');

        $specialSong->update($this->validateSong($request, partial: true));

        return response()->json(['ok' => true, 'song' => $specialSong->fresh()]);
    }

    public function deleteSpecialSong(Request $request, \App\Models\SpecialSong $specialSong): JsonResponse
    {
        PermissionService::require($request->user(), 'special_sundays.manage');
        $specialSong->delete();

        return response()->json(['ok' => true]);
    }

    private function validateSong(Request $request, bool $partial = false): array
    {
        $req  = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'language'    => [$req, 'string', 'in:en,my,td'],
            'title'       => [$req, 'string', 'max:200'],
            'source_type' => [$req, 'string', 'in:youtube,hymn,audio,suno'],
            'source_ref'  => [$req, 'string', 'max:5000'],
            'lyrics'      => ['nullable', 'string', 'max:20000'],
            'mood'        => ['nullable', 'string', 'max:60'],
            'region'      => ['nullable', 'string', 'max:60'],
            'priority'    => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'active'      => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('title', $data))  $data['title']  = $this->normalizeText($data['title']);
        if (array_key_exists('lyrics', $data)) $data['lyrics'] = $this->normalizeText($data['lyrics']);

        return $data;
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
        PermissionService::require(request()->user(), 'settings.view');
        return response()->json($this->settingsPayload());
    }

    /** Worker base URL for the Bible/TTS FastAPI service (same as BibleController). */
    private function bibleWorkerBase(): string
    {
        return rtrim((string) config('services.tedim_llm.url', 'http://127.0.0.1:8001'), '/');
    }

    /** How many of the AI background-music theme x time-of-day matrix are cached. */
    public function bibleBgMusicStatus(): JsonResponse
    {
        PermissionService::require(request()->user(), 'settings.view');
        $resp = Http::timeout(10)->get("{$this->bibleWorkerBase()}/bible/bg-music/status", [
            'storage_backend' => (string) Setting::get('storage_backend', 'local'),
        ]);
        abort_unless($resp->successful(), 502, 'Background music service unavailable');
        return response()->json($resp->json());
    }

    /** Queue generation of every uncached AI background-music loop. (admin-only route) */
    public function bibleBgMusicPregenerate(): JsonResponse
    {
        $resp = Http::timeout(15)->post("{$this->bibleWorkerBase()}/bible/bg-music/pregenerate", [
            'engine'          => Setting::bibleBgMusicEngine(),
            'storage_backend' => (string) Setting::get('storage_backend', 'local'),
        ]);
        abort_unless($resp->successful(), 502, 'Background music service unavailable');
        return response()->json($resp->json());
    }

    /**
     * The background-music library: admin-uploaded tracks + AI-generated loops,
     * each flagged with whether it's the one currently selected to play (static
     * mode). Lets the admin browse, pick, and manage tracks. (admin-only route)
     */
    public function bibleBgMusicLibrary(BibleBgMusicLibrary $library): JsonResponse
    {
        $selected = (string) Setting::get('bible_bg_music_url', '');
        $tracks = array_map(function ($t) use ($selected) {
            $t['selected'] = $selected !== '' && $t['url'] === $selected;

            return $t;
        }, $library->all());

        return response()->json([
            'tracks'       => $tracks,
            'selected_url' => $selected,
            'mode'         => (string) Setting::get('bible_bg_music_mode', 'off'),
        ]);
    }

    /**
     * Upload a track from the admin's device into the library. Validates a small
     * mp3/ogg and (for convenience) selects it as the active static track when
     * nothing is selected yet. (admin-only route)
     */
    public function bibleBgMusicUpload(Request $request, BibleBgMusicLibrary $library): JsonResponse
    {
        $validated = $request->validate([
            // Keep it small — it's a soft instrumental loop, not a full album.
            // Accept by extension as well as MIME: browsers label mp3s variously
            // (audio/mpeg, audio/mp3, audio/x-mpeg…), so a strict mimetypes-only
            // rule rejects perfectly valid files.
            'file'  => ['required', 'file', 'mimes:mp3,mpga,ogg,oga', 'max:10240'],
            'title' => ['nullable', 'string', 'max:80'],
            // Optional mood + time-of-day tags so the reader can auto-pick this
            // track by the same logic AI mode uses ('any' = fits everything).
            'theme' => ['nullable', 'in:any,' . implode(',', BibleBgMusicLibrary::THEMES)],
            'tod'   => ['nullable', 'in:any,' . implode(',', BibleBgMusicLibrary::TODS)],
        ]);

        $track = $library->addUpload($validated['file'], $validated['theme'] ?? 'any', $validated['tod'] ?? 'any');
        if (! empty($validated['title'])) {
            $track['title'] = $validated['title'];
        }

        // First track uploaded with nothing chosen yet → make it the live one, so
        // a single upload "just works" without a second click.
        if ((string) Setting::get('bible_bg_music_url', '') === '') {
            Setting::set('bible_bg_music_url', $track['url']);
            Setting::set('bible_bg_music_mode', 'static');
        }
        $track['selected'] = (string) Setting::get('bible_bg_music_url', '') === $track['url'];

        return response()->json(['track' => $track]);
    }

    /** Delete an uploaded library track; clears the selection if it was live. */
    public function bibleBgMusicDelete(string $id, BibleBgMusicLibrary $library): JsonResponse
    {
        $res = $library->deleteUpload($id);
        abort_if($res === null, 404, 'Track not found.');

        if ((string) Setting::get('bible_bg_music_url', '') === $res['url']) {
            Setting::set('bible_bg_music_url', '');
        }

        return response()->json(['ok' => true]);
    }

    /** Update an uploaded track's mood + time-of-day tags. */
    public function bibleBgMusicTags(string $id, Request $request, BibleBgMusicLibrary $library): JsonResponse
    {
        $data = $request->validate([
            'theme' => ['required', 'in:any,' . implode(',', BibleBgMusicLibrary::THEMES)],
            'tod'   => ['required', 'in:any,' . implode(',', BibleBgMusicLibrary::TODS)],
        ]);

        abort_unless($library->updateTags($id, $data['theme'], $data['tod']), 404, 'Track not found.');

        return response()->json(['ok' => true]);
    }

    /** Choose which library track plays (sets static mode + its URL). */
    public function bibleBgMusicSelect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'src' => ['required', 'in:upload,ai'],
            'key' => ['required', 'string', 'max:64'],
        ]);

        $library = app(BibleBgMusicLibrary::class);
        abort_if($library->resolvePath($data['src'], $data['key']) === null, 404, 'Track not found.');

        $url = $library->url($data['src'], $data['key']);
        Setting::set('bible_bg_music_url', $url);
        Setting::set('bible_bg_music_mode', 'static');

        return response()->json(['url' => $url, 'mode' => 'static']);
    }

    public function updateSettings(UpdateSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();

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
        if (array_key_exists('local_avatar_enabled', $data)) {
            Setting::set('local_avatar_enabled', $data['local_avatar_enabled'] ? '1' : '0');
        }
        if (array_key_exists('text_highlight_enabled', $data)) {
            Setting::set('text_highlight_enabled', $data['text_highlight_enabled'] ? '1' : '0');
        }
        foreach (array_keys(Setting::BIBLE_VERSIONS) as $code) {
            $key = 'bible_narration_mode_' . $code;
            if (array_key_exists($key, $data)) {
                Setting::set($key, $data[$key]);
            }
        }
        if (array_key_exists('bible_text_highlight_enabled', $data)) {
            Setting::set('bible_text_highlight_enabled', $data['bible_text_highlight_enabled'] ? '1' : '0');
        }
        if (array_key_exists('bible_bg_music_mode', $data)) {
            Setting::set('bible_bg_music_mode', $data['bible_bg_music_mode']);
        }
        if (array_key_exists('bible_bg_music_engine', $data)) {
            Setting::set('bible_bg_music_engine', $data['bible_bg_music_engine']);
        }
        if (array_key_exists('bible_bg_music_url', $data)) {
            Setting::set('bible_bg_music_url', trim((string) $data['bible_bg_music_url']));
        }
        if (array_key_exists('bible_bg_music_volume', $data)) {
            Setting::set('bible_bg_music_volume', (string) round((float) $data['bible_bg_music_volume'], 2));
        }
        if (array_key_exists('bible_features', $data)) {
            Setting::setBibleFeatures($data['bible_features']);
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
        // Goldfish LLM narrators (Mizo lus, Paite pck): persist the setting AND
        // mirror it to Redis so the worker's goldfish_service can gate inference.
        foreach (['lus', 'pck'] as $iso) {
            $key = 'narration_' . $iso;
            if (array_key_exists($key, $data)) {
                $on = $data[$key] ? '1' : '0';
                Setting::set($key, $on);
                Redis::set('ai:narration_' . $iso, $on);
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
        if (array_key_exists('orchestration_mode', $data)) {
            $mode = $data['orchestration_mode'];
            Setting::set('orchestration_mode', $mode);
            Redis::set('ai:orchestration_mode', $mode);
        }
        if (array_key_exists('agent_provider', $data)) {
            $provider = $data['agent_provider'];
            Setting::set('agent_provider', $provider);
            Redis::set('ai:agent_provider', $provider);
        }
        if (array_key_exists('runpod_enabled', $data)) {
            $runpod = $data['runpod_enabled'] ? '1' : '0';
            Setting::set('runpod_enabled', $runpod);
            Redis::set('ai:runpod_enabled', $runpod);
        }
        if (array_key_exists('ad_slot_enabled', $data)) {
            Setting::set('ad_slot_enabled', $data['ad_slot_enabled'] ? '1' : '0');
        }
        if (array_key_exists('ad_slot_html', $data)) {
            Setting::set('ad_slot_html', $data['ad_slot_html'] ?? '');
        }
        if (array_key_exists('ai_chords_enabled', $data)) {
            Setting::set('ai_chords_enabled', $data['ai_chords_enabled'] ? '1' : '0');
        }
        if (array_key_exists('ai_chords_model', $data)) {
            Setting::set('ai_chords_model', $data['ai_chords_model'] ?? '');
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
            'local_avatar_enabled' => Setting::get('local_avatar_enabled', '0') === '1',
            'text_highlight_enabled' => Setting::get('text_highlight_enabled', '1') === '1',
            // Online Bible reader: per-language "Listen" voice (inherits the
            // service voice when unset) + verse highlight toggle.
            ...collect(array_keys(Setting::BIBLE_VERSIONS))
                ->mapWithKeys(fn ($code) => ['bible_narration_mode_' . $code => Setting::bibleNarrationMode($code)])
                ->all(),
            'bible_text_highlight_enabled' => Setting::bibleTextHighlightEnabled(),
            'bible_bg_music_mode' => Setting::bibleBgMusicMode(),
            'bible_bg_music_engine' => Setting::bibleBgMusicEngine(),
            'bible_bg_music_url' => Setting::bibleBgMusicUrl(),
            'bible_bg_music_volume' => Setting::bibleBgMusicVolume(),
            'bible_features' => Setting::bibleFeatureMatrix(),
            'runpod_enabled'     => Setting::get('runpod_enabled', '0') === '1',
            // Per-language narration: all on by default.
            // Myanmar/Tedim: edge_tts = Microsoft cloud; mms_tts = local MMS-TTS.
            'narration_en'       => Setting::narrationEnabled('en'),
            'narration_my'       => Setting::narrationEnabled('my'),
            'narration_td'       => Setting::narrationEnabled('td'),
            // Which service languages appear in the intake form.
            'lang_en'            => Setting::get('lang_en', '1') === '1',
            'lang_my'            => Setting::get('lang_my', '0') === '1',
            'lang_td'            => Setting::get('lang_td', '0') === '1',
            // Goldfish LLM narrators (Bible-only Chin/Zo); default on.
            'narration_lus'      => Setting::get('narration_lus', '1') === '1',
            'narration_pck'      => Setting::get('narration_pck', '1') === '1',
            'moods'                => Setting::moods(),
            'music_sources'        => Setting::enabledMusicSources(),
            'scheduling_enabled'   => Setting::schedulingEnabled(),
            'default_music_source' => Setting::defaultMusicSource(),
            'countdown_content_enabled' => Setting::get('countdown_content_enabled', '1') === '1',
            'countdown_content_source'  => Setting::get('countdown_content_source', 'both'),
            'countdown_banners'         => Setting::countdownBanners(),
            'content_filter_keywords'   => Setting::filterKeywords(),
            'orchestration_mode'        => Setting::get('orchestration_mode', 'pipeline'),
            'agent_provider'            => Setting::get('agent_provider', 'claude'),
            'ad_slot_enabled'           => Setting::get('ad_slot_enabled', '0') === '1',
            'ad_slot_html'              => Setting::get('ad_slot_html', ''),
            'ai_chords_enabled'         => Setting::get('ai_chords_enabled', '0') === '1',
            'ai_chords_model'           => Setting::get('ai_chords_model', env('AI_CHORD_MODEL', '')),
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

    /**
     * Credit a registered user's token wallet (support top-up / goodwill grant).
     * The move is recorded in token_ledger as an ADJUSTMENT referencing the acting
     * admin, and the wallet row is locked inside TokenService so it can't race.
     * Guest accounts have no wallet, so they're rejected.
     */
    public function grantTokens(Request $request, User $user, TokenService $tokens): JsonResponse
    {
        if (str_ends_with((string) $user->email, '@guest.local')) {
            return response()->json(['message' => 'Visitors do not have a token wallet.'], 422);
        }

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        $tokens->grant(
            $user,
            (int) $data['amount'],
            LedgerType::ADJUSTMENT,
            'admin:' . $request->user()->id,
        );

        return response()->json([
            'ok'            => true,
            'token_balance' => (int) $user->fresh()->token_balance,
        ]);
    }

    /**
     * Read-only freeze-harness status for the admin console monitor. Parses the
     * synthetic-probe logs the cron jobs write under ../ops/logs and computes a
     * health summary (coverage vs expected cadence, 5xx, failed checks, balance &
     * auth drift, overall verdict). Never mutates anything; if the harness isn't
     * armed it returns armed=false so the UI can show a "not running" state.
     */
    public function freezeStatus(): JsonResponse
    {
        $ops = dirname(base_path()) . '/ops';
        $readJsonl = function (string $path): array {
            $out = [];
            if (is_readable($path)) {
                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $row = json_decode($line, true);
                    if (is_array($row)) $out[] = $row;
                }
            }
            return $out;
        };

        $api = $readJsonl("$ops/logs/freeze.jsonl");
        $brw = $readJsonl("$ops/logs/freeze_browser.jsonl");

        $tsList = [];
        foreach ($api as $r) {
            if (!empty($r['ts'])) { try { $tsList[] = Carbon::parse($r['ts']); } catch (\Throwable $e) {} }
        }
        $now = Carbon::now('UTC');
        $start = $tsList ? min($tsList) : null;
        $lastTs = $tsList ? max($tsList) : null;
        $ageH = $start ? $start->diffInSeconds($now) / 3600.0 : 0.0;
        // The .freeze_env file is chmod 600 (has credentials), so php-fpm can't read
        // it. Infer "armed/live" from recent probe activity instead — a cycle within
        // the last 20 min means cron is actively writing.
        $armed = $lastTs && $lastTs->diffInMinutes($now) <= 20;

        $api5xx = array_sum(array_map(fn($r) => (int) ($r['err5xx'] ?? 0), $api));
        $failedChecks = array_sum(array_map(fn($r) => count($r['fails'] ?? []), $api));
        $bals = array_values(array_filter(array_map(fn($r) => $r['balance'] ?? null, $api), 'is_int'));
        $regressions = 0;
        for ($i = 1; $i < count($bals); $i++) if ($bals[$i] < $bals[$i - 1]) $regressions++;
        $brwFail = count(array_filter($brw, fn($r) => !($r['ok'] ?? false)));
        $brwConsole = array_sum(array_map(fn($r) => (int) ($r['console_issues'] ?? 0), $brw));
        $authDrift = $brwFail + array_sum(array_map(
            fn($r) => count(array_filter($r['fails'] ?? [], fn($f) => str_starts_with($f, 'session') || str_starts_with($f, 'logout'))),
            $api
        ));

        $expApi = max(0, (int) ($ageH * 6));
        $expBrw = max(0, (int) ($ageH * 1));
        $coverage = $expApi > 0 ? round(count($api) / $expApi * 100, 1) : 0.0;

        $hardFail = $api5xx > 0 || $failedChecks > 0 || $regressions > 0 || $brwFail > 0;
        if (!$api && !$brw)      $verdict = 'PENDING';
        elseif ($hardFail)       $verdict = 'FAIL';
        elseif ($coverage < 80)  $verdict = 'WARN';
        else                     $verdict = 'GREEN';

        // A rendered verdict file (written by the gate at T+24h) overrides the live read.
        $verdictFile = "$ops/logs/freeze_verdict.txt";
        $renderedVerdict = null;
        if (is_readable($verdictFile)) {
            $first = trim((string) (file($verdictFile)[0] ?? ''));
            if (str_contains($first, ':')) $renderedVerdict = trim(explode(':', $first, 2)[1]);
        }

        $trim = fn(array $rows, array $keys) => array_map(
            fn($r) => array_intersect_key($r, array_flip($keys)),
            array_slice($rows, -10)
        );

        return response()->json([
            'armed'        => $armed,
            'now'          => $now->toIso8601String(),
            'window_start' => $start?->toIso8601String(),
            'window_end'   => $start?->copy()->addHours(24)->toIso8601String(),
            'age_hours'    => round($ageH, 2),
            'verdict'      => $verdict,
            'rendered_verdict' => $renderedVerdict,
            'health' => [
                'api_cycles'     => count($api),
                'browser_cycles' => count($brw),
                'expected_api'   => $expApi,
                'expected_brw'   => $expBrw,
                'coverage'       => $coverage,
                'err5xx'         => $api5xx,
                'failed_checks'  => $failedChecks,
                'balance_drift'  => $regressions,
                'auth_drift'     => $authDrift,
                'console_issues' => $brwConsole,
                'balance'        => $bals ? end($bals) : null,
            ],
            'api_cycles' => $trim($api, ['ts', 'ok', 'err5xx', 'fails', 'balance', 'checks']),
            'browser_cycles' => $trim($brw, ['ts', 'ok', 'console_issues', 'note']),
        ]);
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

        if ($user->isAdmin() && User::where('role', User::ROLE_ADMIN)->count() <= 1) {
            return response()->json(['message' => 'Cannot delete the last admin account.'], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['ok' => true]);
    }

    /** Delete many users (and all their associated data) atomically. */
    public function bulkDeleteUsers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_ids'   => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer'],
        ]);

        // An admin can never delete their own account in a bulk operation.
        $ids = array_values(array_diff($data['user_ids'], [$request->user()->id]));

        $deleted = 0;

        DB::transaction(function () use ($ids, &$deleted) {
            $users = User::whereIn('id', $ids)->get();

            foreach ($users as $user) {
                // Cascade each user's services and their assets/intakes manually,
                // mirroring deleteService(), since the User model has no deleting event.
                foreach ($user->sessions as $session) {
                    $session->assets()->delete();
                    $session->intake()->delete();
                    $session->delete();
                }

                $user->ledgerEntries()->delete();
                $user->tokens()->delete();
                $user->delete();
                $deleted++;
            }
        });

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }

    /** Grant or revoke admin. Guards against an admin removing their own access. */
    public function setAdmin(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['is_admin' => ['required', 'boolean']]);

        if ($user->id === $request->user()->id && ! $data['is_admin']) {
            return response()->json(['message' => 'You cannot revoke your own admin access.'], 422);
        }

        if (! $data['is_admin'] && $user->isAdmin()
            && User::where('role', User::ROLE_ADMIN)->where('id', '!=', $user->id)->doesntExist()) {
            return response()->json(['message' => 'Cannot revoke the last admin account.'], 422);
        }

        $user->update([
            'is_admin' => $data['is_admin'],
            'role'     => $data['is_admin'] ? User::ROLE_ADMIN : User::ROLE_MEMBER,
        ]);

        return response()->json([
            'ok'       => true,
            'is_admin' => (bool) $data['is_admin'],
            'role'     => $data['is_admin'] ? User::ROLE_ADMIN : User::ROLE_MEMBER,
        ]);
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
        PermissionService::require(request()->user(), 'permissions.view');
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

    /** List sentences from the language data files for grammar review. */
    public function grammarReview(Request $request): JsonResponse
    {
        PermissionService::require($request->user(), 'language_review.view');
        $lang    = in_array($request->query('lang'), ['td', 'my']) ? $request->query('lang') : 'td';
        $type    = $request->query('type', 'hymn_titles');
        $status  = in_array($request->query('status'), ['all', 'pending', 'approved', 'corrected'])
                   ? $request->query('status') : 'all';
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = 20;

        $reviewFile = base_path('../workers/data/grammar_review.json');
        $reviews    = [];
        if (file_exists($reviewFile)) {
            $reviews = json_decode(file_get_contents($reviewFile), true) ?? [];
        }

        $sentences = $this->extractGrammarSentences($lang, $type);

        $sentences = array_map(function ($s) use ($reviews) {
            $r             = $reviews[$s['key']] ?? null;
            $s['status']   = $r ? ($r['correction'] ? 'corrected' : 'approved') : 'pending';
            $s['correction'] = $r['correction'] ?? null;
            return $s;
        }, $sentences);

        if ($status !== 'all') {
            $sentences = array_values(array_filter($sentences, fn($s) => $s['status'] === $status));
        }

        $total     = count($sentences);
        $sentences = array_slice($sentences, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'sentences' => $sentences,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
        ]);
    }

    /** Save an approval or correction for a sentence. */
    public function grammarReviewSave(Request $request): JsonResponse
    {
        PermissionService::require($request->user(), 'language_review.view');
        $data = $request->validate([
            'key'        => ['required', 'string', 'max:300'],
            'action'     => ['required', 'string', 'in:approve,correct,reset'],
            'correction' => ['nullable', 'string', 'max:10000'],
        ]);

        $reviewFile = base_path('../workers/data/grammar_review.json');
        $reviews    = [];
        if (file_exists($reviewFile)) {
            $reviews = json_decode(file_get_contents($reviewFile), true) ?? [];
        }

        if ($data['action'] === 'reset') {
            unset($reviews[$data['key']]);
        } elseif ($data['action'] === 'approve') {
            $reviews[$data['key']] = [
                'approved'   => true,
                'correction' => null,
                'updated_at' => now()->toIso8601String(),
            ];
        } else {
            $text = trim($data['correction'] ?? '');
            if ($text === '') {
                return response()->json(['error' => 'Correction text is required.'], 422);
            }
            $reviews[$data['key']] = [
                'approved'   => false,
                'correction' => $text,
                'updated_at' => now()->toIso8601String(),
            ];
        }

        $written = @file_put_contents(
            $reviewFile,
            json_encode($reviews, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        if ($written === false) {
            Log::error('grammar-review: unable to write review file', ['file' => $reviewFile]);

            return response()->json([
                'error' => 'Could not save the review. The server cannot write to its data directory — please contact an administrator.',
            ], 500);
        }

        return response()->json(['ok' => true]);
    }

    private function extractGrammarSentences(string $lang, string $type): array
    {
        $dir       = base_path('../workers/data');
        $sentences = [];

        if ($lang === 'my' && $type === 'prayers') {
            $data = json_decode(file_get_contents("$dir/prayers_my.json"), true);
            foreach ($data['categories'] as $catKey => $cat) {
                foreach ($cat['prayers'] as $i => $prayer) {
                    $sentences[] = [
                        'key'      => "my_prayer_{$catKey}_{$i}",
                        'lang'     => 'my',
                        'type'     => 'prayer',
                        'category' => $cat['label_en'] ?? $catKey,
                        'text'     => $prayer,
                        'text_en'  => null,
                        'extra'    => null,
                    ];
                }
            }
        } elseif ($lang === 'td' && $type === 'sermons') {
            $data = json_decode(file_get_contents("$dir/sermons_td.json"), true);
            foreach ($data['phases'] as $phaseKey => $phase) {
                foreach (($phase['sermons'] ?? []) as $sermon) {
                    $sentences[] = [
                        'key'      => "td_sermon_{$sermon['id']}",
                        'lang'     => 'td',
                        'type'     => 'sermon',
                        'category' => $phase['label_en'] ?? $phaseKey,
                        'text'     => $sermon['title'],
                        'text_en'  => $sermon['title_en'] ?? null,
                        'extra'    => $sermon['summary'] ?? null,
                    ];
                }
            }
        } elseif ($type === 'hymn_titles') {
            $file = $lang === 'td' ? 'hymns_td.json' : 'hymns_my.json';
            $data = json_decode(file_get_contents("$dir/$file"), true);
            foreach ($data['hymns'] as $hymn) {
                $slug        = $hymn['slug'] ?? ($hymn['number'] ?? '');
                $sentences[] = [
                    'key'      => "{$lang}_hymn_title_{$slug}",
                    'lang'     => $lang,
                    'type'     => 'hymn_title',
                    'category' => 'Hymn Title',
                    'text'     => $hymn['title'],
                    'text_en'  => $hymn['title_en'] ?? null,
                    'extra'    => null,
                ];
            }
        } elseif ($type === 'hymn_lyrics') {
            $file = $lang === 'td' ? 'hymns_td.json' : 'hymns_my.json';
            $data = json_decode(file_get_contents("$dir/$file"), true);
            foreach ($data['hymns'] as $hymn) {
                $lyrics = trim($hymn['lyrics'] ?? '');
                if (! $lyrics) continue;
                $slug        = $hymn['slug'] ?? ($hymn['number'] ?? '');
                $sentences[] = [
                    'key'      => "{$lang}_hymn_lyrics_{$slug}",
                    'lang'     => $lang,
                    'type'     => 'hymn_lyrics',
                    'category' => $hymn['title'],
                    'text'     => $lyrics,
                    'text_en'  => $hymn['title_en'] ?? null,
                    'extra'    => null,
                ];
            }
        }

        return $sentences;
    }

    /**
     * Create a new user account directly from the admin console.
     * Returns the user and (optionally) a password-reset link when no
     * password is supplied, so the admin can share a first-login link.
     */
    public function createUser(
        Request $request,
        \App\Services\AccountActivationService $activation,
        \App\Services\TokenService $tokens
    ): JsonResponse {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'role'     => ['required', 'string', 'in:' . implode(',', User::ASSIGNABLE_ROLES)],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        // Admin-created accounts are active by default (ADMIN_USERS_REQUIRE_VERIFICATION
        // = false); flip the env to require email verification like a self-registrant.
        $requireVerification = (bool) config('account.admin_requires_verification', false);

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
            'status'        => $requireVerification ? User::STATUS_PENDING : User::STATUS_ACTIVE,
        ]);

        if ($requireVerification) {
            // Defer the token grant to activation, exactly like self-service signup.
            $activation->startVerification($user);
        } else {
            // Active immediately: stamp verification and grant the plan's monthly package
            // through the ledger-backed refill (no-op for guests).
            $user->forceFill(['email_verified_at' => now()])->save();
            $tokens->refillMonthly($user);
        }

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

    /**
     * Read-only Knowledge Operations Platform health summary for the admin dashboard.
     * Probes the embedding worker, inspects each configured Qdrant corpus collection,
     * and reflects the active config. Never mutates anything; safe to call at any time.
     */
    public function knowledgeHealth(): JsonResponse
    {
        PermissionService::require(request()->user(), 'knowledge.view');

        $http = Http::getFacadeRoot();

        // ── Worker health ────────────────────────────────────────────────────
        $workerHealth = null;
        $workerOk     = false;
        if (config('knowledge.embedding.driver') === 'worker') {
            $url = rtrim((string) config('knowledge.embedding.worker_url', ''), '/') . '/knowledge/health';
            try {
                $resp         = $http->timeout(5)->get($url);
                $workerHealth = $resp->successful() ? $resp->json() : ['error' => "HTTP {$resp->status()}"];
                $workerOk     = (bool) ($workerHealth['ok'] ?? false);
            } catch (\Throwable $e) {
                $workerHealth = ['error' => $e->getMessage()];
            }
        }

        // ── Qdrant collections ───────────────────────────────────────────────
        $corpora    = (array) config('knowledge.corpora', []);
        $qdrantUrl  = rtrim((string) config('knowledge.vector.qdrant.url', ''), '/');
        $qdrantKey  = config('knowledge.vector.qdrant.key');
        $collections = [];

        if (config('knowledge.vector.driver') === 'qdrant' && $qdrantUrl !== '') {
            foreach ($corpora as $corpus) {
                try {
                    $req = $http->timeout(5);
                    if ($qdrantKey) {
                        $req = $req->withHeaders(['api-key' => $qdrantKey]);
                    }
                    $resp = $req->get("{$qdrantUrl}/collections/{$corpus}");
                    if ($resp->successful()) {
                        $r = $resp->json('result', []);
                        $collections[$corpus] = [
                            'exists'        => true,
                            'status'        => $r['status'] ?? 'unknown',
                            'vectors_count' => $r['vectors_count'] ?? 0,
                            'points_count'  => $r['points_count'] ?? 0,
                            'vector_size'   => $r['config']['params']['vectors']['size'] ?? null,
                            'distance'      => $r['config']['params']['vectors']['distance'] ?? null,
                        ];
                    } else {
                        $collections[$corpus] = ['exists' => false];
                    }
                } catch (\Throwable) {
                    $collections[$corpus] = ['exists' => false, 'error' => 'unreachable'];
                }
            }
        }

        return response()->json([
            'enabled'          => (bool) config('knowledge.enabled'),
            'embedding_driver' => config('knowledge.embedding.driver', 'hash'),
            'vector_driver'    => config('knowledge.vector.driver', 'memory'),
            'embedding_dims'   => (int) config('knowledge.embedding.dimensions', 384),
            'corpora'          => $corpora,
            'worker'           => $workerHealth,
            'worker_ok'        => $workerOk,
            'collections'      => $collections,
            'source_priority'  => (array) config('knowledge.source_priority', []),
            'last_retrieval'   => \Illuminate\Support\Facades\Cache::get('knowledge.last_retrieval'),
        ]);
    }
}
