<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Models\JournalEntry;
use App\Models\SessionNode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Church-wide aggregate analytics for the staff console (read-only). Unlike the
 * per-user Spiritual Journey stats, this spans ALL users — so it lives behind the
 * 'staff' admin gate, never the owner-scoped history API.
 */
class HistoryAdminController extends Controller
{
    public function churchAnalytics(): JsonResponse
    {
        $since7 = now()->subDays(7);
        $since30 = now()->subDays(30);

        return response()->json([
            'users' => [
                'total'     => User::count(),
                'new_30d'   => User::where('created_at', '>=', $since30)->count(),
                'active_7d' => ChatSession::where('last_activity_at', '>=', $since7)
                    ->distinct()->count('user_id'),
            ],
            'sessions' => [
                'total'    => ChatSession::count(),
                'last_30d' => ChatSession::where('started_at', '>=', $since30)->count(),
                'by_type'  => ChatSession::select('session_type', DB::raw('count(*) as c'))
                    ->groupBy('session_type')->pluck('c', 'session_type'),
            ],
            'engagement' => [
                'message_turns'   => SessionNode::where('type', 'message')->count(),
                'journal_entries' => JournalEntry::count(),
            ],
        ]);
    }
}
