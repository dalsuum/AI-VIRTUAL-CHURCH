<?php

namespace App\Http\Controllers;

use App\Jobs\DispatchServiceJob;
use App\Models\CrisisIntercept;
use App\Models\FinancialLedger;
use App\Models\ServiceIntake;
use App\Models\ServiceSession;
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

    /** Recent services, newest first, with their user and segment counts. */
    public function services(): JsonResponse
    {
        $services = ServiceSession::with('user:id,name,email')
            ->withCount('assets')
            ->latest()
            ->limit(50)
            ->get(['id', 'user_id', 'session_token', 'status', 'scheduled_at', 'created_at']);

        return response()->json(['services' => $services]);
    }

    /** Re-run the AI pipeline for a session (e.g. after a worker outage). */
    public function retryService(ServiceSession $service): JsonResponse
    {
        abort_if($service->intake === null, 422, 'Session has no intake to regenerate from.');

        $service->update(['status' => 'active']);
        DispatchServiceJob::dispatch($service->id);

        return response()->json(['ok' => true, 'status' => 'active']);
    }

    /** All testimonies (pending first) for moderation. */
    public function testimonies(): JsonResponse
    {
        $testimonies = Testimony::with('user:id,name')
            ->orderBy('approved')
            ->latest()
            ->limit(100)
            ->get();

        return response()->json(['testimonies' => $testimonies]);
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
            ->get(['id', 'name', 'email', 'is_admin', 'music_source', 'created_at'])
            ->map(function ($u) {
                $isGuest = str_ends_with($u->email, '@guest.local');

                return [
                    'id'           => $u->id,
                    'name'         => $u->name,
                    'email'        => $isGuest ? null : $u->email,
                    'is_admin'     => $u->is_admin,
                    'is_guest'     => $isGuest,
                    'music_source' => $u->music_source,
                    'visits'       => $u->sessions_count,
                    'last_seen'    => $u->sessions_max_created_at,
                    'created_at'   => $u->created_at,
                ];
            });

        return response()->json(['users' => $users]);
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
