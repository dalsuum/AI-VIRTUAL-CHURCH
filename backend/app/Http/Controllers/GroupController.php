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
                    'join_url'   => config('app.url').'/#join?token='.$i->token,
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
}
