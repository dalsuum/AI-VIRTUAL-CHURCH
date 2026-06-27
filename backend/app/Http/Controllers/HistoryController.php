<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Models\ChatSessionShare;
use App\Models\ChatSessionTag;
use App\Services\HistoryExportService;
use App\Services\HistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unified history API (/api/history/*). Every route is owner-scoped: a session is
 * only ever loaded through findOwned(), which 404s anything the caller doesn't own
 * (no existence oracle). Cursor-paginated + a Redis-cached first page for the sidebar.
 */
class HistoryController extends Controller
{
    /** Keep the pinned section a curated shortlist, not a second unbounded list. */
    private const MAX_PINNED = 20;

    public function __construct(
        private readonly HistoryService $history,
        private readonly HistoryExportService $exporter,
        private readonly \App\Services\SessionState\SessionStateStore $state,
    ) {}

    /** Load a session owned by the caller, or 404. */
    private function findOwned(Request $request, string $id): ChatSession
    {
        return ChatSession::forUser((int) $request->user()->id)
            ->whereKey($id)
            ->firstOr(fn () => abort(Response::HTTP_NOT_FOUND));
    }

    /**
     * Hydrate a session's `messages` for serialization from session_nodes — the sole
     * durable record since Phase 4 dropped the legacy chat_messages projection.
     */
    private function withMessages(ChatSession $session): ChatSession
    {
        $session->setRelation('messages', $this->state->messageDtos($session));

        return $session;
    }

    /** Sidebar list — grouped by date bucket, cursor-paginated, optional filters. */
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $type   = $request->query('type');
        $archived = $request->boolean('archived');
        $trashed = $request->boolean('trashed');
        $cursor = $request->query('cursor');
        $folderId = $request->query('folder_id');

        // First, unfiltered page is hot — cache it briefly per user.
        $cacheable = ! $type && ! $archived && ! $trashed && ! $cursor && $folderId === null;
        if ($cacheable && ($hit = Cache::get("history:list:{$userId}"))) {
            return response()->json($hit);
        }

        $query = ($trashed ? ChatSession::onlyTrashed() : ChatSession::query())
            ->forUser($userId)
            ->with('tags')
            ->when(! $trashed, fn ($q) => $q->where('archived', $archived))
            ->when($type, fn ($q) => $q->where('session_type', $type))
            ->when($folderId !== null, fn ($q) => $q->where('folder_id', $folderId ?: null))
            ->orderByDesc('last_activity_at');

        $page = $query->cursorPaginate(30, ['*'], 'cursor', $cursor);

        $payload = [
            'pinned'      => ($cursor || $trashed) ? [] : $this->summaryList(
                ChatSession::forUser($userId)->where('pinned', true)
                    ->where('archived', false)->with('tags')
                    ->orderByDesc('last_activity_at')->get()
            ),
            'groups'      => $this->groupByDate($page->items()),
            'next_cursor' => optional($page->nextCursor())->encode(),
        ];

        if ($cacheable) {
            Cache::put("history:list:{$userId}", $payload, now()->addMinutes(5));
        }

        return response()->json($payload);
    }

    /** Full session for resume: messages + type metadata. */
    public function show(Request $request, string $id): JsonResponse
    {
        $session = $this->withMessages($this->findOwned($request, $id)->load('tags'));
        if ($rel = $session->metaRelation()) {
            $session->load($rel);
        }

        return response()->json(['session' => $session]);
    }

    /**
     * Fork a session at a node into a new branch-session (SessionStateStore graph).
     * Defaults to the active node. The new session shares root/parent lineage; the
     * caller continues the conversation on the child without touching the parent.
     */
    public function fork(Request $request, string $id): JsonResponse
    {
        $session = $this->findOwned($request, $id);
        $data = $request->validate(['from_node_id' => ['nullable', 'string']]);

        $fromNodeId = $data['from_node_id'] ?? $session->active_node_id;
        abort_if($fromNodeId === null, 422, 'Nothing to fork from yet — this session has no nodes.');

        $childId = $this->state->fork($session->id, $fromNodeId);
        $this->history->forgetListCache((int) $request->user()->id);
        $this->audit($request, 'history.fork', $session->id);

        $child = ChatSession::find($childId);

        return response()->json(['session' => $child], Response::HTTP_CREATED);
    }

    /** Search across the journal (FULLTEXT on MySQL, LIKE fallback elsewhere). */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q'         => ['nullable', 'string', 'max:120'],
            'type'      => ['nullable', 'in:' . implode(',', ChatSession::TYPES)],
            'language'  => ['nullable', 'string', 'max:12'],
            'mood'      => ['nullable', 'string', 'max:40'],
            'scope'     => ['nullable', 'in:meta,all'],
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date'],
        ]);

        $q = trim((string) ($data['q'] ?? ''));
        $userId = (int) $request->user()->id;
        $query = ChatSession::forUser($userId)->with('tags');

        if ($q !== '') {
            // The query is already owner-scoped (forUser), so the LIKE scan is bounded to a
            // single user's sessions via the (user_id, *) index — fast and exact (no FULLTEXT
            // min-token / stopword surprises). scope=all additionally matches inside message
            // bodies: node content is encrypted at rest (no SQL LIKE), so we decrypt the
            // owner's message nodes in PHP (bounded to one user) and OR-in the matching ids.
            $bodyIds = ($data['scope'] ?? 'meta') === 'all'
                ? $this->messageBodyMatches($userId, $q)
                : [];
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $query->where(function ($w) use ($like, $bodyIds) {
                $w->where('title', 'like', $like)->orWhere('summary', 'like', $like);
                if ($bodyIds !== []) {
                    $w->orWhereIn('id', $bodyIds);
                }
            });
        }

        $query->when($data['type'] ?? null, fn ($x, $v) => $x->where('session_type', $v))
            ->when($data['language'] ?? null, fn ($x, $v) => $x->where('language', $v))
            ->when($data['mood'] ?? null, fn ($x, $v) => $x->where('mood', $v))
            ->when($data['date_from'] ?? null, fn ($x, $v) => $x->where('started_at', '>=', Carbon::parse($v)))
            ->when($data['date_to'] ?? null, fn ($x, $v) => $x->where('started_at', '<=', Carbon::parse($v)->endOfDay()));

        return response()->json([
            'results' => $this->summaryList($query->orderByDesc('last_activity_at')->limit(50)->get()),
        ]);
    }

    /**
     * Session ids (owner-scoped) whose message-node content contains $q. Node content is
     * encrypted at rest, so the match is done in PHP after decryption — bounded to one
     * user's sessions, with a hard cap on nodes scanned to keep it predictable.
     *
     * @return array<int,string>
     */
    private function messageBodyMatches(int $userId, string $q): array
    {
        $sessionIds = ChatSession::forUser($userId)->pluck('id');
        if ($sessionIds->isEmpty()) {
            return [];
        }

        $needle = mb_strtolower($q);
        $matched = [];
        \App\Models\SessionNode::whereIn('session_id', $sessionIds)
            ->where('type', 'message')
            ->orderByDesc('id')
            ->limit(5000)                                    // safety cap on per-user scan
            ->get(['session_id', 'content'])
            ->each(function ($n) use (&$matched, $needle) {
                if ($n->content !== null && str_contains(mb_strtolower($n->content), $needle)) {
                    $matched[$n->session_id] = true;
                }
            });

        return array_keys($matched);
    }

    /** Rename / favorite / pin / archive / rate / set tags. */
    public function update(Request $request, string $id): JsonResponse
    {
        $session = $this->findOwned($request, $id);

        $data = $request->validate([
            'title'    => ['sometimes', 'string', 'max:200'],
            'favorite' => ['sometimes', 'boolean'],
            'pinned'   => ['sometimes', 'boolean'],
            'archived' => ['sometimes', 'boolean'],
            'rating'   => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5'],
            'tags'     => ['sometimes', 'array', 'max:20'],
            'tags.*'   => ['string', 'max:40'],
        ]);

        // Cap pinned sessions so the pinned section stays a curated shortlist, not a
        // second unbounded list. Only enforced when transitioning into pinned.
        if (($data['pinned'] ?? false) === true && ! $session->pinned) {
            $pinned = ChatSession::forUser((int) $request->user()->id)->where('pinned', true)->count();
            abort_if($pinned >= self::MAX_PINNED, 422, 'You can pin at most ' . self::MAX_PINNED . ' sessions.');
        }

        if (array_key_exists('tags', $data)) {
            $this->syncTags($session, $data['tags']);
            unset($data['tags']);
        }
        if ($data) {
            $session->update($data);
        }
        $this->history->forgetListCache($session->user_id);

        return response()->json(['session' => $session->fresh('tags')]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $session = $this->findOwned($request, $id);
        $session->delete();                                       // soft delete
        $this->history->forgetListCache($session->user_id);
        $this->audit($request, 'history.delete', $session->id);

        return response()->json(['ok' => true]);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $session = ChatSession::withTrashed()->forUser((int) $request->user()->id)
            ->whereKey($id)->firstOr(fn () => abort(Response::HTTP_NOT_FOUND));
        $session->restore();
        $this->history->forgetListCache($session->user_id);

        return response()->json(['session' => $session]);
    }

    /**
     * Apply one action to many owned sessions at once (sidebar multi-select).
     * Owner-scoped via forUser(); withTrashed() so 'untrash' can reach soft-deleted rows.
     * delete = soft delete · archive/unarchive = toggle archived · untrash = restore ·
     * purge = permanent force-delete (children cascade via FK; not recoverable).
     */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:delete,archive,unarchive,untrash,purge'],
            'ids'    => ['required', 'array', 'min:1', 'max:200'],
            'ids.*'  => ['string'],
        ]);
        $userId = (int) $request->user()->id;

        $sessions = ChatSession::withTrashed()->forUser($userId)
            ->whereIn('id', $data['ids'])->get();

        DB::transaction(function () use ($sessions, $data) {
            foreach ($sessions as $session) {
                match ($data['action']) {
                    'delete'    => $session->delete(),                  // soft delete
                    'archive'   => $session->update(['archived' => true]),
                    'unarchive' => $session->update(['archived' => false]),
                    'untrash'   => $session->restore(),
                    'purge'     => $session->forceDelete(),             // permanent
                };
            }
        });

        $this->history->forgetListCache($userId);
        $this->audit($request, "history.bulk.{$data['action']}", implode(',', $sessions->pluck('id')->all()));

        return response()->json(['ok' => true, 'affected' => $sessions->count()]);
    }

    /** Single-session export, or the whole journal via export-all. */
    public function export(Request $request, string $id)
    {
        $format = (string) $request->query('format', 'md');
        $session = $this->withMessages($this->findOwned($request, $id)->load('tags'));

        return $this->stream($this->exporter->export($session, $format));
    }

    public function exportAll(Request $request)
    {
        $format = (string) $request->query('format', 'md');
        $sessions = ChatSession::forUser((int) $request->user()->id)
            ->with('tags')->orderBy('started_at')->get()
            ->each(fn ($s) => $this->withMessages($s));

        return $this->stream($this->exporter->export($sessions, $format));
    }

    private function stream(array $file)
    {
        return response($file['body'], 200, [
            'Content-Type'        => $file['mime'],
            'Content-Disposition' => 'attachment; filename="' . $file['filename'] . '"',
        ]);
    }

    /** Create a read-only share link; returns the plaintext token ONCE. */
    public function share(Request $request, string $id): JsonResponse
    {
        $session = $this->findOwned($request, $id);
        $data = $request->validate([
            'password'   => ['nullable', 'string', 'min:4', 'max:72'],
            'expires_in' => ['nullable', 'integer', 'min:1', 'max:8760'],   // hours, ≤1y
        ]);

        $share = new ChatSessionShare(['chat_session_id' => $session->id]);
        $token = $share->issueToken();
        $share->setPassword($data['password'] ?? null);
        $share->expires_at = isset($data['expires_in'])
            ? now()->addHours((int) $data['expires_in']) : null;
        $share->save();

        $this->audit($request, 'history.share', $session->id);

        return response()->json([
            'token'      => $token,                              // shown once
            'url'        => url('/api/shared/' . $token),
            'expires_at' => $share->expires_at,
            'has_password' => $share->password !== null,
        ]);
    }

    public function revokeShare(Request $request, string $id): JsonResponse
    {
        $session = $this->findOwned($request, $id);
        $session->shares()->whereNull('revoked_at')->update(['revoked_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** Public read-only view of a shared session (no auth). */
    public function viewShared(Request $request, string $token): JsonResponse
    {
        $share = ChatSessionShare::where('token', ChatSessionShare::hashToken($token))->first();
        abort_unless($share && $share->isActive(), Response::HTTP_NOT_FOUND);
        abort_unless($share->checkPassword($request->query('password')), Response::HTTP_FORBIDDEN, 'Password required.');

        $session = $this->withMessages(ChatSession::with('tags')->findOrFail($share->chat_session_id));

        return response()->json([
            'session' => [
                'title'      => $session->title,
                'type'       => $session->session_type,
                'summary'    => $session->summary,
                'started_at' => $session->started_at,
                'tags'       => $session->tags->pluck('tag'),
                'messages'   => $session->messages->map(fn ($m) => [
                    'sender' => $m->sender, 'content' => $m->content,
                ]),
            ],
        ]);
    }

    /** Spiritual Journey dashboard aggregates. */
    public function stats(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $base = ChatSession::forUser($userId);

        $byType = (clone $base)->select('session_type', DB::raw('count(*) as c'))
            ->groupBy('session_type')->pluck('c', 'session_type');

        $topTags = ChatSessionTag::whereIn('chat_session_id',
                (clone $base)->select('id'))
            ->select('tag', DB::raw('count(*) as c'))
            ->groupBy('tag')->orderByDesc('c')->limit(8)->pluck('c', 'tag');

        $favBook = DB::table('bible_sessions')
            ->whereIn('chat_session_id', (clone $base)->select('id'))
            ->select('book', DB::raw('count(*) as c'))
            ->whereNotNull('book')->groupBy('book')->orderByDesc('c')->value('book');

        return response()->json([
            'counts' => [
                'bible_study' => (int) ($byType['bible_study'] ?? 0),
                'prayer'      => (int) ($byType['prayer'] ?? 0),
                'music'       => (int) ($byType['music'] ?? 0),
                'service'     => (int) ($byType['service'] ?? 0),
                'pastor'      => (int) ($byType['pastor'] ?? 0),
                'total'       => (int) $byType->sum(),
            ],
            'favorite_book'  => $favBook,
            'top_topics'     => $topTags,
            'streak_days'    => $this->streak($userId),
            'first_session'  => optional((clone $base)->min('started_at')),
        ]);
    }

    /** Chronological timeline grouped by month for a year. */
    public function timeline(Request $request): JsonResponse
    {
        $year = (int) $request->query('year', now()->year);
        $sessions = ChatSession::forUser((int) $request->user()->id)
            ->whereYear('started_at', $year)
            ->orderBy('started_at')
            ->get(['id', 'session_type', 'title', 'started_at', 'mood']);

        $groups = $sessions->groupBy(fn ($s) => optional($s->started_at)->format('F'))
            ->map(fn ($items) => $this->summaryList($items))->all();

        return response()->json(['year' => $year, 'months' => $groups]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function syncTags(ChatSession $session, array $tags): void
    {
        $tags = collect($tags)->map(fn ($t) => trim($t))->filter()->unique()->take(20);
        $session->tags()->delete();
        foreach ($tags as $tag) {
            ChatSessionTag::create([
                'chat_session_id' => $session->id, 'tag' => $tag, 'auto' => false,
            ]);
        }
    }

    /** @param iterable<ChatSession> $items */
    private function summaryList(iterable $items): array
    {
        return collect($items)->map(fn (ChatSession $s) => [
            'id'               => $s->id,
            'type'             => $s->session_type,
            'title'            => $s->title ?: ucfirst(str_replace('_', ' ', $s->session_type)),
            'mood'             => $s->mood,
            'language'         => $s->language,
            'pinned'           => $s->pinned,
            'favorite'         => $s->favorite,
            'archived'         => $s->archived,
            'last_activity_at' => $s->last_activity_at,
            'tags'             => $s->relationLoaded('tags') ? $s->tags->pluck('tag') : [],
        ])->values()->all();
    }

    /** @param iterable<ChatSession> $items */
    private function groupByDate(iterable $items): array
    {
        $buckets = ['Today' => [], 'Yesterday' => [], 'Previous 7 Days' => [],
            'Previous 30 Days' => [], 'Older' => []];
        $now = now();
        foreach ($items as $s) {
            $d = $s->last_activity_at ?? $s->created_at;
            $bucket = match (true) {
                $d->isToday()                  => 'Today',
                $d->isYesterday()              => 'Yesterday',
                $d->gt($now->copy()->subDays(7))  => 'Previous 7 Days',
                $d->gt($now->copy()->subDays(30)) => 'Previous 30 Days',
                default                        => 'Older',
            };
            $buckets[$bucket][] = $this->summaryList([$s])[0];
        }

        return array_filter($buckets, fn ($v) => $v !== []);
    }

    private function streak(int $userId): int
    {
        $days = ChatSession::forUser($userId)
            ->where('started_at', '>=', now()->subDays(60))
            ->orderByDesc('started_at')
            ->pluck('started_at')
            ->map(fn ($d) => $d->toDateString())->unique()->values();

        $streak = 0;
        $cursor = now()->startOfDay();
        foreach ($days as $day) {
            if ($day === $cursor->toDateString()) {
                $streak++;
                $cursor->subDay();
            } elseif ($day === $cursor->copy()->subDay()->toDateString() && $streak === 0) {
                // allow streak to count from yesterday if nothing today yet
                $streak++;
                $cursor = now()->startOfDay()->subDays(2);
            } else {
                break;
            }
        }

        return $streak;
    }

    private function audit(Request $request, string $action, string $sessionId): void
    {
        try {
            DB::table('ai_audit_log')->insert([
                'actor_user_id' => $request->user()->id,
                'action'        => $action,
                'entity_type'   => 'chat_session',
                'after'         => json_encode(['session_id' => $sessionId]),
                'ip'            => $request->ip(),
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            // Audit is best-effort; never block the user action on a logging miss.
        }
    }
}
