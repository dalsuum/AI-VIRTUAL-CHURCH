<?php

namespace App\Http\Controllers;

use App\Jobs\DispatchServiceJob;
use App\Models\CrisisIntercept;
use App\Models\FinancialLedger;
use App\Models\ServiceIntake;
use App\Models\ServiceSession;
use App\Models\Setting;
use App\Models\Testimony;
use App\Models\User;
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
        $service->assets()->delete();
        $service->intake()->delete();
        $service->delete();

        return response()->json(['ok' => true]);
    }

    /** All testimonies (pending first) for moderation. */
    public function testimonies(): JsonResponse
    {
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
        $testimony->update(['approved' => true]);

        return response()->json(['ok' => true]);
    }

    public function deleteTestimony(Testimony $testimony): JsonResponse
    {
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
                    'is_admin'     => $u->is_admin,
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
                $l->user?->name ?? '—',
                $l->user && ! str_ends_with($l->user->email, '@guest.local') ? $l->user->email : '',
                $l->amount,
                strtoupper($l->currency),
                $l->allocation_type,
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
                $u->name,
                str_ends_with($u->email, '@guest.local') ? '(visitor)' : $u->email,
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
                $t->user?->name ?? '—',
                $t->source,
                $t->approved ? 'approved' : 'pending',
                $t->content,
            ]);

        return [['Date', 'By', 'Source', 'Status', 'Content'], $rows];
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
            // off = silent text; browser = browser speech synthesis;
            // edge_tts = Microsoft Edge neural TTS (free, no key);
            // openai = OpenAI TTS; kokoro = hexgrad/kokoro-82m via OpenRouter.
            'narration_mode'  => ['sometimes', 'string', 'in:' . implode(',', Setting::NARRATION_MODES)],
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
            // Whether the "schedule it" option appears in the intake form.
            'scheduling_enabled' => ['sometimes', 'boolean'],
            // The music source pre-selected in the intake form.
            'default_music_source' => ['sometimes', 'string', 'in:' . implode(',', Setting::MUSIC_SOURCES)],
            // Toggle avatar video rendering on/off without touching env vars.
            'avatar_enabled'      => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('narration_mode', $data)) {
            Setting::set('narration_mode', $data['narration_mode']);
        }
        if (array_key_exists('edge_tts_voice', $data)) {
            Setting::set('edge_tts_voice', $data['edge_tts_voice']);
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

        return response()->json(['ok' => true] + $this->settingsPayload());
    }

    /** The settings shape shared by the read and write endpoints. */
    private function settingsPayload(): array
    {
        return [
            'narration_mode'     => Setting::get('narration_mode', 'browser'),
            'edge_tts_voice'     => Setting::get('edge_tts_voice', 'en-US-AriaNeural'),
            'music_reuse'        => Setting::get('music_reuse', '1') === '1',
            'storage_backend'    => Setting::get('storage_backend', 'local'),
            'avatar_enabled'     => Setting::get('avatar_enabled', '1') === '1',
            'moods'                => Setting::moods(),
            'music_sources'        => Setting::enabledMusicSources(),
            'scheduling_enabled'   => Setting::schedulingEnabled(),
            'default_music_source' => Setting::defaultMusicSource(),
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
}
