<?php

namespace App\Http\Controllers;

use App\Domains\Bible\Models\ReadingSession;
use App\Domains\Groups\Models\Group;
use App\Domains\Groups\Models\GroupMembership;
use App\Domains\Invitations\Models\Invitation;
use App\Enums\GroupRole;
use App\Enums\InvitationKind;
use App\Enums\InvitationStatus;
use Illuminate\Http\Request;

/**
 * Read surface for the Group Page (v1.3 Phase F). Mutations stay with their
 * domain owners (InvitationService, ReadingSessionService, GroupPolicy-gated
 * creation on ChurchController) — this controller only projects state.
 * All abilities defer to GroupPolicy; manager-only extras never leak to members.
 */
class GroupController extends Controller
{
    /** Group header + Today's Status in one call. Manager extras (pending request
     *  count, active links) are included only for GroupPolicy::manage holders. */
    public function show(Request $request, Group $group)
    {
        $this->authorize('view', $group);
        $user      = $request->user();
        $isManager = $user->can('manage', $group);

        $openSession = $group->readingSessions()
            ->whereNotIn('status', ReadingSession::TERMINAL)->with('plan')->latest()->first();

        $leaders = $group->memberships()
            ->where('role', GroupRole::LEADER)->where('status', GroupMembership::STATUS_ACTIVE)
            ->with('user')->get()->map(fn ($m) => $m->user?->name)->filter()->values();

        $data = [
            'id'           => $group->id,
            'name'         => $group->name,
            'type'         => $group->type->value,
            'description'  => $group->description,
            'church'       => ['id' => $group->church_id, 'name' => $group->church?->name],
            'member_count' => $group->memberships()->where('status', GroupMembership::STATUS_ACTIVE)->count(),
            'leaders'      => $leaders,
            'my_role'      => $user->groupRole($group->id)?->value,
            'can_manage'   => $isManager,
            'open_session' => $openSession ? [
                'id'         => $openSession->id,
                'status'     => $openSession->status,
                'plan_title' => $openSession->plan?->title,
            ] : null,
            // The viewer's own open join request, so the UI can offer "withdraw".
            'my_pending_request' => Invitation::query()
                ->where('kind', InvitationKind::REQUEST)
                ->where('inviter_id', $user->id)
                ->where('invitable_type', $group->getMorphClass())
                ->where('invitable_id', $group->id)
                ->where('status', InvitationStatus::PENDING)
                ->value('id'),
        ];

        if ($isManager) {
            $data['pending_request_count'] = Invitation::query()
                ->where('kind', InvitationKind::REQUEST)
                ->where('invitable_type', $group->getMorphClass())
                ->where('invitable_id', $group->id)
                ->where('status', InvitationStatus::PENDING)->count();

            $data['links'] = Invitation::query()
                ->where('kind', InvitationKind::LINK)
                ->where('invitable_type', $group->getMorphClass())
                ->where('invitable_id', $group->id)
                ->where('status', InvitationStatus::PENDING)
                ->latest()->get()->map(fn ($i) => [
                    'id'         => $i->id,
                    'join_url'   => rtrim((string) config('church.frontend_url'), '/').'/#join?token='.$i->token,
                    'max_uses'   => $i->max_uses,
                    'use_count'  => $i->use_count,
                    'expires_at' => optional($i->expires_at)->toIso8601String(),
                ])->values();
        }

        return response()->json($data);
    }

    /** Group roster — visible to anyone who can view the group. */
    public function members(Request $request, Group $group)
    {
        $this->authorize('view', $group);

        $members = $group->memberships()
            ->where('status', GroupMembership::STATUS_ACTIVE)
            ->with('user')->orderBy('joined_at')->get()->map(fn ($m) => [
                'id'        => $m->user_id,
                'name'      => $m->user?->name,
                'role'      => $m->role->value,
                'joined_at' => optional($m->joined_at)->toIso8601String(),
            ]);

        return response()->json(['members' => $members]);
    }

    /**
     * Change a member's GROUP role (member ⇄ leader) — v1.4 governance. Group
     * leadership is an APPOINTMENT: every join path (links, requests, email)
     * deliberately enters people as plain members; this is the one way someone
     * becomes a worship/study/choir leader. Manage-gated (the group's own leader
     * or church elder+); never your own role.
     */
    public function updateMemberRole(Request $request, Group $group, \App\Models\User $user)
    {
        $this->authorize('manage', $group);

        $data = $request->validate([
            'role' => ['required', \Illuminate\Validation\Rule::in(['member', 'leader'])],
        ]);

        if ($request->user()->id === $user->id) {
            abort(403, 'You cannot change your own role.');
        }

        $membership = GroupMembership::query()
            ->where('group_id', $group->id)->where('user_id', $user->id)
            ->where('status', GroupMembership::STATUS_ACTIVE)->firstOrFail();

        $before = $membership->role->value;
        $membership->forceFill(['role' => GroupRole::from($data['role'])])->save();

        logger()->info('group role changed', [
            'group_id' => $group->id, 'target_id' => $user->id,
            'actor_id' => $request->user()->id, 'from' => $before, 'to' => $data['role'],
        ]);

        return response()->json(['id' => $user->id, 'role' => $data['role']]);
    }

    /**
     * Minimal group activity feed: a PROJECTION over existing rows (memberships,
     * sessions, invitations) — no event store, no new tables. A persisted feed
     * built on the frozen domain events can replace this without changing the
     * response contract. Entries are typed; the client renders the sentences.
     */
    public function activity(Request $request, Group $group)
    {
        $this->authorize('view', $group);

        $joins = GroupMembership::query()
            ->where('group_id', $group->id)->where('status', GroupMembership::STATUS_ACTIVE)
            ->whereNotNull('joined_at')->with('user')
            ->latest('joined_at')->limit(15)->get()
            ->map(fn ($m) => ['type' => 'member_joined', 'at' => $m->joined_at, 'actor' => $m->user?->name, 'subject' => null]);

        $sessions = ReadingSession::query()
            ->where('group_id', $group->id)->whereNotNull('started_at')->with('plan')
            ->latest('started_at')->limit(10)->get()
            ->map(fn ($s) => ['type' => 'session_started', 'at' => $s->started_at, 'actor' => null, 'subject' => $s->plan?->title]);

        $invitations = Invitation::query()
            ->where('invitable_type', $group->getMorphClass())->where('invitable_id', $group->id)
            ->with('inviter')->latest()->limit(20)->get()
            ->flatMap(fn ($i) => array_values(array_filter([
                $i->kind === InvitationKind::LINK
                    ? ['type' => 'link_created', 'at' => $i->created_at, 'actor' => $i->inviter?->name, 'subject' => null]
                    : null,
                $i->kind === InvitationKind::REQUEST && $i->status === InvitationStatus::ACCEPTED
                    ? ['type' => 'request_approved', 'at' => $i->responded_at, 'actor' => $i->inviter?->name, 'subject' => null]
                    : null,
            ])));

        $items = $joins->concat($sessions)->concat($invitations)
            ->filter(fn ($e) => $e['at'] !== null)
            ->sortByDesc('at')->take(20)->values()
            ->map(fn ($e) => [...$e, 'at' => $e['at']->toIso8601String()]);

        return response()->json(['activity' => $items]);
    }

    // ── Group service (v1.4): share one generated service with the group ───────

    /** The group's current shared service (latest), for the Group Page card. */
    public function service(Request $request, Group $group)
    {
        $this->authorize('view', $group);

        $s = \App\Models\ServiceSession::query()
            ->where('group_id', $group->id)->latest()->with('user')->first();

        return response()->json(['service' => $s ? [
            'session_token' => $s->session_token,
            'status'        => $s->status,
            'language'      => $s->language,
            'shared_by'     => $s->user?->name,
            'created_at'    => optional($s->created_at)->toIso8601String(),
        ] : null]);
    }

    /** Share one of YOUR OWN services with the group (managers). The pipeline is
     *  untouched — sharing is an ownership flag; playback authorizes members. */
    public function shareService(Request $request, Group $group)
    {
        $this->authorize('manage', $group);

        $data = $request->validate([
            'session_token' => ['required', 'string', 'max:128'],
        ]);

        $service = \App\Models\ServiceSession::query()
            ->where('session_token', $data['session_token'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $service->forceFill(['group_id' => $group->id])->save();

        return $this->service($request, $group);
    }

    /** Stop sharing (managers). The service itself remains the owner's. */
    public function unshareService(Request $request, Group $group)
    {
        $this->authorize('manage', $group);

        \App\Models\ServiceSession::query()
            ->where('group_id', $group->id)->update(['group_id' => null]);

        return response()->json(['service' => null]);
    }

    // ── Group study room (v1.4): study together — same share pattern ───────────

    /** The group's current study room (latest non-detached), for the Group Page. */
    public function studyRoom(Request $request, Group $group)
    {
        $this->authorize('view', $group);

        $s = \App\Models\StudySession::query()
            ->where('group_id', $group->id)->latest()->first();

        return response()->json(['study' => $s ? [
            'id'    => $s->id,
            'topic' => $s->topic,
            'state' => $s->state,
            'owner' => \App\Models\User::find($s->user_id)?->name,
        ] : null]);
    }

    /** Open one of YOUR OWN study sessions as the group's room (managers). Every
     *  member can then read along and ask; AI rounds bill the OWNER (creator-pays,
     *  owner decision) since the reserve pipeline keys off session->user_id. */
    public function attachStudy(Request $request, Group $group)
    {
        $this->authorize('manage', $group);

        $data = $request->validate(['study_session_id' => ['required', 'integer']]);

        $study = \App\Models\StudySession::query()
            ->where('id', $data['study_session_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $study->forceFill(['group_id' => $group->id])->save();

        return $this->studyRoom($request, $group);
    }

    /** Close the room (managers). The conversation remains the owner's session. */
    public function detachStudy(Request $request, Group $group)
    {
        $this->authorize('manage', $group);

        \App\Models\StudySession::query()
            ->where('group_id', $group->id)->update(['group_id' => null]);

        return response()->json(['study' => null]);
    }
}
