<?php

namespace App\Http\Controllers;

use App\Domains\Groups\Models\Group;
use App\Domains\Invitations\Models\Invitation;
use App\Domains\Invitations\Services\InvitationService;
use App\Enums\InvitationActivity;
use App\Enums\InvitationKind;
use App\Enums\InvitationStatus;
use App\Http\Requests\CreateInvitationRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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

    /** Invitations addressed to me (pending) and the ones I sent. */
    public function index(Request $request)
    {
        $me = $request->user()->id;

        return response()->json([
            'received' => Invitation::with('inviter')
                ->where('invitee_id', $me)->where('status', InvitationStatus::PENDING)
                ->latest()->get()->map(fn ($i) => $this->present($i)),
            'sent' => Invitation::with('invitee')
                ->where('inviter_id', $me)->latest()->limit(50)->get()->map(fn ($i) => $this->present($i)),
        ]);
    }

    public function store(CreateInvitationRequest $request)
    {
        $data    = $request->validated();
        $invitee = User::findOrFail($data['invitee_id']);

        $invitation = $this->invitations->send(
            inviter: $request->user(),
            invitee: $invitee,
            activity: InvitationActivity::from($data['activity']),
            scheduledAt: isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null,
            timezone: $data['timezone'] ?? null,
            message: $data['message'] ?? null,
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

    /** Preview a link before joining: what group, whose invitation, still usable? */
    public function showLink(Request $request, string $token)
    {
        $i = Invitation::query()
            ->where('kind', InvitationKind::LINK)->where('token', $token)
            ->with(['invitable.church', 'inviter'])->firstOrFail();

        return response()->json([
            'group'      => ['id' => $i->invitable->id, 'name' => $i->invitable->name, 'type' => $i->invitable->type->value],
            'church'     => ['id' => $i->invitable->church->id, 'name' => $i->invitable->church->name],
            'inviter'    => ['id' => $i->inviter_id, 'name' => $i->inviter?->name],
            'message'    => $i->message,
            'expires_at' => optional($i->expires_at)->toIso8601String(),
            'usable'     => $i->isPending() && ! $i->hasExpired() && $i->hasRemainingUses(),
        ]);
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

        if ($i->kind === InvitationKind::LINK) {
            $base += [
                'token'     => $i->token,
                'join_url'  => config('app.url').'/#join?token='.$i->token,   // QR renders this client-side
                'max_uses'  => $i->max_uses,
                'use_count' => $i->use_count,
                'group'     => $i->relationLoaded('invitable') && $i->invitable
                    ? ['id' => $i->invitable->id, 'name' => $i->invitable->name] : null,
            ];
        }

        return $base;
    }
}
