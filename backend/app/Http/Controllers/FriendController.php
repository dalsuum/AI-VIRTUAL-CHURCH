<?php

namespace App\Http\Controllers;

use App\Domains\Friends\Models\Friendship;
use App\Domains\Friends\Services\FriendshipService;
use App\Enums\FriendStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Thin orchestration over FriendshipService — the controller validates input and
 * delegates; it never mutates friendships or re-implements state rules. All actions
 * are scoped to the authenticated user; the target is resolved by route binding.
 */
class FriendController extends Controller
{
    public function __construct(private readonly FriendshipService $friends)
    {
    }

    /** Accepted friends of the current user, newest first, with the favorite flag. */
    public function index(Request $request)
    {
        $me = $request->user()->id;

        $rows = Friendship::query()
            ->where('status', FriendStatus::ACCEPTED)
            ->where(fn ($q) => $q->where('user_id', $me)->orWhere('friend_id', $me))
            ->latest('responded_at')
            ->get();

        $friends = $rows->map(function (Friendship $f) use ($me) {
            $otherId = $f->user_id === $me ? $f->friend_id : $f->user_id;

            return [
                'user'      => $this->publicUser(User::find($otherId)),
                'favorited' => $f->isFavoritedBy($me),
            ];
        })->filter(fn ($r) => $r['user'] !== null)->values();

        return response()->json(['friends' => $friends]);
    }

    /** Pending friend requests addressed TO the current user. */
    public function requests(Request $request)
    {
        $me = $request->user()->id;

        $incoming = Friendship::query()
            ->where('status', FriendStatus::PENDING)
            ->where('requested_by', '!=', $me)
            ->where(fn ($q) => $q->where('user_id', $me)->orWhere('friend_id', $me))
            ->latest()
            ->get()
            ->map(fn (Friendship $f) => $this->publicUser($f->requester))
            ->filter()->values();

        return response()->json(['requests' => $incoming]);
    }

    /** Search members by name/email, excluding self and blocked pairs. */
    public function search(Request $request)
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']]);
        $me   = $request->user();
        $term = '%' . str_replace(['%', '_'], ['\%', '\_'], $data['q']) . '%';

        $matches = User::query()
            ->where('id', '!=', $me->id)
            ->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term))
            ->limit(20)->get()
            ->reject(fn (User $u) => Friendship::blockExistsBetween($me->id, $u->id))
            ->map(fn (User $u) => $this->publicUser($u))
            ->values();

        return response()->json(['results' => $matches]);
    }

    public function request(Request $request, User $user)
    {
        $this->authorize('friend-interact', $user);

        return response()->json($this->friends->request($request->user(), $user), 201);
    }

    public function accept(Request $request, User $user)
    {
        return response()->json($this->friends->accept($request->user(), $user));
    }

    public function reject(Request $request, User $user)
    {
        $this->friends->reject($request->user(), $user);

        return response()->noContent();
    }

    public function cancel(Request $request, User $user)
    {
        $this->friends->cancel($request->user(), $user);

        return response()->noContent();
    }

    public function remove(Request $request, User $user)
    {
        $this->friends->remove($request->user(), $user);

        return response()->noContent();
    }

    public function block(Request $request, User $user)
    {
        return response()->json($this->friends->block($request->user(), $user));
    }

    public function unblock(Request $request, User $user)
    {
        $this->friends->unblock($request->user(), $user);

        return response()->noContent();
    }

    public function favorite(Request $request, User $user)
    {
        $data = $request->validate(['favorite' => ['required', 'boolean']]);

        return response()->json($this->friends->setFavorite($request->user(), $user, $data['favorite']));
    }

    /** Minimal, non-sensitive projection of another member (never leaks email). */
    private function publicUser(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }
}
