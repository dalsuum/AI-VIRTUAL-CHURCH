<?php

namespace App\Http\Controllers;

use App\Domains\Invitations\Models\Invitation;
use App\Domains\Invitations\Services\InvitationService;
use App\Enums\InvitationActivity;
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

    /** Stable API projection of an invitation. */
    private function present(Invitation $i): array
    {
        return [
            'id'           => $i->id,
            'activity'     => $i->activity->value,
            'status'       => $i->status->value,
            'inviter'      => $i->relationLoaded('inviter') && $i->inviter
                ? ['id' => $i->inviter->id, 'name' => $i->inviter->name] : ['id' => $i->inviter_id],
            'invitee'      => $i->relationLoaded('invitee') && $i->invitee
                ? ['id' => $i->invitee->id, 'name' => $i->invitee->name] : ['id' => $i->invitee_id],
            'message'      => $i->message,
            'scheduled_at' => optional($i->scheduled_at)->toIso8601String(),
            'expires_at'   => optional($i->expires_at)->toIso8601String(),
        ];
    }
}
