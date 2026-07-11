<?php

namespace App\Http\Controllers;

use App\Domains\Groups\Models\Group;
use App\Domains\Invitations\Models\Invitation;
use App\Domains\Invitations\Services\InvitationService;
use App\Enums\InvitationActivity;
use App\Enums\InvitationKind;
use App\Enums\InvitationStatus;
use App\Http\Requests\CreateInvitationRequest;
use App\Domains\Invitations\Notifications\GroupInviteEmail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Thin orchestration over InvitationService — the sole mutator of invitation state.
 * The controller validates, authorizes via policy, and delegates; it never writes
 * status itself.
 */
class InvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations)
    {
    }

    /** Invitations addressed to me — pending ones to act on, plus recent ACCEPTED
     *  ones (so an accepted couple-worship invitation stays reopenable) — and the
     *  ones I sent. */
    public function index(Request $request)
    {
        $me = $request->user()->id;

        return response()->json([
            'received' => Invitation::with(['inviter', 'invitable'])
                ->where('invitee_id', $me)
                ->whereIn('status', [InvitationStatus::PENDING, InvitationStatus::ACCEPTED])
                ->latest()->limit(20)->get()->map(fn ($i) => $this->present($i)),
            'sent' => Invitation::with(['invitee', 'invitable'])
                ->where('inviter_id', $me)->latest()->limit(50)->get()->map(fn ($i) => $this->present($i)),
        ]);
    }

    public function store(CreateInvitationRequest $request)
    {
        $data    = $request->validated();
        $invitee = User::findOrFail($data['invitee_id']);

        // Couple worship (v1.4): attach ONE OF YOUR OWN services so acceptance
        // admits the invitee to exactly that service (ownership enforced here).
        $invitable = null;
        if (! empty($data['service_token'])) {
            $invitable = \App\Models\ServiceSession::query()
                ->where('session_token', $data['service_token'])
                ->where('user_id', $request->user()->id)
                ->firstOrFail();
        }

        $invitation = $this->invitations->send(
            inviter: $request->user(),
            invitee: $invitee,
            activity: InvitationActivity::from($data['activity']),
            scheduledAt: isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null,
            timezone: $data['timezone'] ?? null,
            message: $data['message'] ?? null,
            invitable: $invitable,
        );

        return response()->json($this->present($invitation), 201);
    }

    public function show(Request $request, Invitation $invitation)
    {
        $this->authorize('view', $invitation);

        return response()->json($this->present($invitation->load(['inviter', 'invitee'])));
    }

    public function accept(Request $request, Invitation $invitation)
    {
        $this->authorize('respond', $invitation);

        return response()->json($this->present($this->invitations->accept($request->user(), $invitation)));
    }

    public function decline(Request $request, Invitation $invitation)
    {
        $this->authorize('respond', $invitation);

        return response()->json($this->present($this->invitations->decline($request->user(), $invitation)));
    }

    public function cancel(Request $request, Invitation $invitation)
    {
        $this->authorize('cancel', $invitation);

        return response()->json($this->present($this->invitations->cancel($request->user(), $invitation)));
    }

    /** Mint a shareable LINK invitation into a group (group leader or church elder+). */
    public function storeLink(Request $request, Group $group)
    {
        $this->authorize('manage', $group);

        $data = $request->validate([
            'max_uses'   => ['nullable', 'integer', 'min:1', 'max:10000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'message'    => ['nullable', 'string', 'max:500'],
        ]);

        $invitation = $this->invitations->sendLink(
            inviter: $request->user(),
            group: $group,
            maxUses: $data['max_uses'] ?? null,
            expiresAt: isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
            message: $data['message'] ?? null,
        );

        return response()->json($this->present($invitation->load('invitable')), 201);
    }

    /** Email a personal invitation to any address — the recipient may have no
     *  account yet; the join page handles register → auto-return → join. The
     *  mail delivers an ordinary LINK (single-use, 14 days, revocable): email
     *  is a delivery channel, never a second invitation system. */
    public function storeEmailInvitation(Request $request, Group $group)
    {
        $this->authorize('manage', $group);

        $data = $request->validate([
            'email'   => ['required', 'email', 'max:120'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $invitation = $this->invitations->sendLink(
            inviter: $request->user(),
            group: $group,
            maxUses: 1,
            expiresAt: now()->addDays(14),
            message: $data['message'] ?? null,
        );

        Notification::route('mail', $data['email'])->notify(new GroupInviteEmail(
            inviterName: (string) $request->user()->name,
            groupName: $group->name,
            churchName: $group->church?->name,
            token: (string) $invitation->token,
            message: $invitation->message,
        ));

        return response()->json($this->present($invitation->load('invitable')), 201);
    }

    /** Preview a link before joining: what group, whose invitation, still usable?
     *  PUBLIC (token = capability) and informational only — redeem() remains the
     *  authoritative check for validity, expiry, revocation and permissions. */
    public function showLink(Request $request, string $token)
    {
        $i = Invitation::query()
            ->where('kind', InvitationKind::LINK)->where('token', $token)
            ->with(['invitable.church', 'inviter'])->firstOrFail();

        return response()->json([
            'group'      => [
                'id'           => $i->invitable->id,
                'name'         => $i->invitable->name,
                'type'         => $i->invitable->type->value,
                'member_count' => $i->invitable->memberships()
                    ->where('status', \App\Domains\Groups\Models\GroupMembership::STATUS_ACTIVE)->count(),
            ],
            'church'     => ['id' => $i->invitable->church->id, 'name' => $i->invitable->church->name],
            'inviter'    => ['id' => $i->inviter_id, 'name' => $i->inviter?->name],
            'message'    => $i->message,
            'expires_at' => optional($i->expires_at)->toIso8601String(),
            'usable'     => $i->isPending() && ! $i->hasExpired() && $i->hasRemainingUses(),
        ]);
    }

    /** Ask to join a group (kind=request). Idempotent: asking again returns the open request. */
    public function storeRequest(Request $request, Group $group)
    {
        $this->authorize('view', $group);

        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $invitation = $this->invitations->requestToJoin($request->user(), $group, $data['message'] ?? null);

        return response()->json($this->present($invitation->load('invitable')), 201);
    }

    /** Pending join requests for a group — its managers review and approve/decline
     *  through the ordinary /invitations/{id}/accept|decline endpoints. */
    public function indexRequests(Request $request, Group $group)
    {
        $this->authorize('manage', $group);

        return response()->json(
            Invitation::query()
                ->where('kind', InvitationKind::REQUEST)
                ->where('invitable_type', $group->getMorphClass())
                ->where('invitable_id', $group->id)
                ->where('status', InvitationStatus::PENDING)
                ->with('inviter')->latest()->get()
                ->map(fn ($i) => $this->present($i))->values(),
        );
    }

    /** Join the group behind a link. Idempotent for an already-active member. */
    public function redeem(Request $request, string $token)
    {
        $invitation = Invitation::query()
            ->where('kind', InvitationKind::LINK)->where('token', $token)->firstOrFail();

        $membership = $this->invitations->redeem($request->user(), $invitation);

        return response()->json([
            'group_id' => $membership->group_id,
            'role'     => $membership->role->value,
            'status'   => $membership->status,
        ]);
    }

    /** Stable API projection of an invitation. LINK adds token/uses/group (links only
     *  ever surface to their creator or group managers — never in a 'received' inbox). */
    private function present(Invitation $i): array
    {
        $base = [
            'id'           => $i->id,
            'kind'         => $i->kind->value,
            'activity'     => $i->activity->value,
            'status'       => $i->status->value,
            'inviter'      => $i->relationLoaded('inviter') && $i->inviter
                ? ['id' => $i->inviter->id, 'name' => $i->inviter->name] : ['id' => $i->inviter_id],
            'invitee'      => $i->invitee_id === null ? null
                : ($i->relationLoaded('invitee') && $i->invitee
                    ? ['id' => $i->invitee->id, 'name' => $i->invitee->name] : ['id' => $i->invitee_id]),
            'message'      => $i->message,
            'scheduled_at' => optional($i->scheduled_at)->toIso8601String(),
            'expires_at'   => optional($i->expires_at)->toIso8601String(),
        ];

        if ($i->kind !== InvitationKind::DIRECT) {
            $base['group'] = $i->relationLoaded('invitable') && $i->invitable
                ? ['id' => $i->invitable->id, 'name' => $i->invitable->name] : null;
        }

        // Couple worship (v1.4): only an ACCEPTED invitation reveals the service
        // token — before acceptance the token would be a capability leak (playback
        // also re-checks acceptance server-side; belt and braces).
        if ($i->kind === InvitationKind::DIRECT
            && $i->status === InvitationStatus::ACCEPTED
            && $i->relationLoaded('invitable')
            && $i->invitable instanceof \App\Models\ServiceSession) {
            $base['service_token'] = $i->invitable->session_token;
        }

        if ($i->kind === InvitationKind::LINK) {
            $base += [
                'token'     => $i->token,
                'join_url'  => rtrim((string) config('church.frontend_url'), '/').'/#join?token='.$i->token,   // QR renders this client-side
                'max_uses'  => $i->max_uses,
                'use_count' => $i->use_count,
            ];
        }

        return $base;
    }
}
