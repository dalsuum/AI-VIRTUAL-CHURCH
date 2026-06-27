<?php

namespace App\Domains\Friends\Services;

use App\Domains\Accounts\Services\PrivacyGate;
use App\Domains\Friends\Events\FriendBlocked;
use App\Domains\Friends\Events\FriendFavorited;
use App\Domains\Friends\Events\FriendRemoved;
use App\Domains\Friends\Events\FriendRequestAccepted;
use App\Domains\Friends\Events\FriendRequestRejected;
use App\Domains\Friends\Events\FriendRequestSent;
use App\Domains\Friends\Events\FriendUnblocked;
use App\Domains\Friends\Exceptions\FriendshipException;
use App\Domains\Friends\Models\Friendship;
use App\Enums\FriendStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The ONE owner of the friendship state machine. Every transition (request, accept,
 * reject, cancel, remove, block, unblock, favorite) goes through here so there is a
 * single transition validator, a single audit/event source and a single place that
 * understands the canonical-pair row. Controllers orchestrate; they never mutate
 * friendships directly.
 *
 * States (derived from the live canonical row): NONE (no row / soft-deleted),
 * PENDING, ACCEPTED, BLOCKED. Removal/reject/cancel/unblock soft-delete the row back
 * to NONE; a later request restores it. A BLOCK overrides every other relationship.
 *
 * Each mutation runs in a transaction with a row lock so concurrent taps (double
 * accept, request races) are idempotent and safe.
 */
class FriendshipService
{
    public function __construct(private readonly PrivacyGate $privacy)
    {
    }

    /**
     * Send a friend request NONE → PENDING. If the other user already has a pending
     * request out to the actor, this accepts it instead (mutual-request shortcut).
     */
    public function request(User $actor, User $target): Friendship
    {
        $this->assertDistinct($actor, $target);

        // Block / friend-only refusal is a privacy decision, centralized in the gate.
        if (! $this->privacy->canInteract($actor, $target)) {
            throw FriendshipException::forbidden('You cannot send a friend request to this user.');
        }

        return DB::transaction(function () use ($actor, $target) {
            $row = $this->lockCanonical($actor, $target);

            if ($row && ! $row->trashed()) {
                return match ($row->status) {
                    FriendStatus::ACCEPTED => throw FriendshipException::conflict('You are already friends.'),
                    FriendStatus::BLOCKED  => throw FriendshipException::forbidden('You cannot send a friend request to this user.'),
                    FriendStatus::PENDING  => $row->requested_by === $actor->id
                        ? $row                                    // idempotent: already requested
                        : $this->accept($actor, $target),         // they asked first → accept
                };
            }

            $saved = $this->writePair($actor, $target, FriendStatus::PENDING, $row, [
                'requested_by' => $actor->id,
                'blocked_by'   => null,
                'responded_at' => null,
            ]);

            FriendRequestSent::dispatch($actor->id, $target->id);

            return $saved;
        });
    }

    /** Accept a pending request PENDING → ACCEPTED. Only the invitee may accept. */
    public function accept(User $actor, User $target): Friendship
    {
        return DB::transaction(function () use ($actor, $target) {
            $row = $this->requirePending($actor, $target, mustBeInvitee: true);
            $row->update(['status' => FriendStatus::ACCEPTED, 'responded_at' => now()]);

            FriendRequestAccepted::dispatch($actor->id, $target->id);

            return $row;
        });
    }

    /** Decline a pending request PENDING → NONE. Only the invitee may reject. */
    public function reject(User $actor, User $target): void
    {
        DB::transaction(function () use ($actor, $target) {
            $row = $this->requirePending($actor, $target, mustBeInvitee: true);
            $row->delete();

            FriendRequestRejected::dispatch($actor->id, $target->id);
        });
    }

    /**
     * Withdraw your own pending request PENDING → NONE. Only the requester may cancel.
     * Emits no event — nothing for the other side to react to.
     */
    public function cancel(User $actor, User $target): void
    {
        DB::transaction(function () use ($actor, $target) {
            $row = $this->requirePending($actor, $target, mustBeInvitee: false);
            $row->delete();
        });
    }

    /** Unfriend ACCEPTED → NONE. Either party may remove. */
    public function remove(User $actor, User $target): void
    {
        DB::transaction(function () use ($actor, $target) {
            $row = $this->lockCanonical($actor, $target);
            if (! $row || $row->trashed() || $row->status !== FriendStatus::ACCEPTED) {
                throw FriendshipException::conflict('You are not friends with this user.');
            }
            $row->delete();

            FriendRemoved::dispatch($actor->id, $target->id);
        });
    }

    /**
     * Block a user from ANY state → BLOCKED. A block overrides every other
     * relationship, so it also clears any favorite. Idempotent if already blocked
     * by the actor.
     */
    public function block(User $actor, User $target): Friendship
    {
        $this->assertDistinct($actor, $target);

        return DB::transaction(function () use ($actor, $target) {
            $row = $this->lockCanonical($actor, $target);

            if ($row && ! $row->trashed()
                && $row->status === FriendStatus::BLOCKED
                && $row->blocked_by === $actor->id) {
                return $row; // already blocked by this actor
            }

            $saved = $this->writePair($actor, $target, FriendStatus::BLOCKED, $row, [
                'requested_by' => $row && ! $row->trashed() ? $row->requested_by : $actor->id,
                'blocked_by'   => $actor->id,
                'favorited_by' => null,
                'responded_at' => now(),
            ]);

            FriendBlocked::dispatch($actor->id, $target->id);

            return $saved;
        });
    }

    /** Lift a block BLOCKED → NONE. Only the user who issued the block may unblock. */
    public function unblock(User $actor, User $target): void
    {
        DB::transaction(function () use ($actor, $target) {
            $row = $this->lockCanonical($actor, $target);
            if (! $row || $row->trashed() || $row->status !== FriendStatus::BLOCKED) {
                throw FriendshipException::conflict('This user is not blocked.');
            }
            if ($row->blocked_by !== $actor->id) {
                throw FriendshipException::forbidden('Only the user who blocked may unblock.');
            }
            $row->delete();

            FriendUnblocked::dispatch($actor->id, $target->id);
        });
    }

    /** Toggle a one-sided favorite on an existing friendship. Requires ACCEPTED. */
    public function setFavorite(User $actor, User $target, bool $favorite): Friendship
    {
        return DB::transaction(function () use ($actor, $target, $favorite) {
            $row = $this->lockCanonical($actor, $target);
            if (! $row || $row->trashed() || $row->status !== FriendStatus::ACCEPTED) {
                throw FriendshipException::conflict('You can only favorite a friend.');
            }

            $ids = collect($row->favorited_by ?? [])->reject(fn ($id) => $id === $actor->id);
            if ($favorite) {
                $ids->push($actor->id);
            }
            $row->update(['favorited_by' => $ids->values()->all()]);

            if ($favorite) {
                FriendFavorited::dispatch($actor->id, $target->id);
            }

            return $row;
        });
    }

    // --- internals -------------------------------------------------------------

    private function assertDistinct(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw FriendshipException::conflict('You cannot perform this action on yourself.');
        }
    }

    /** Lock and return the canonical row (including trashed) for the pair, or null. */
    private function lockCanonical(User $actor, User $target): ?Friendship
    {
        return Friendship::withTrashed()
            ->forPair($actor->id, $target->id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Require a live PENDING row and check direction. mustBeInvitee=true means the
     * actor must be the one who RECEIVED the request (accept/reject); false means the
     * actor must be the requester (cancel).
     */
    private function requirePending(User $actor, User $target, bool $mustBeInvitee): Friendship
    {
        $row = $this->lockCanonical($actor, $target);
        if (! $row || $row->trashed() || $row->status !== FriendStatus::PENDING) {
            throw FriendshipException::conflict('There is no pending request for this user.');
        }
        $actorIsInvitee = $row->requested_by !== $actor->id;
        if ($mustBeInvitee !== $actorIsInvitee) {
            throw FriendshipException::forbidden('You cannot perform this action on this request.');
        }

        return $row;
    }

    /**
     * Create, or restore-and-update, the canonical row in [min,max] order with the
     * given status. $existing may be a trashed row to revive (keeps the unique slot).
     */
    private function writePair(User $actor, User $target, FriendStatus $status, ?Friendship $existing, array $attrs): Friendship
    {
        [$lo, $hi] = Friendship::orderedPair($actor->id, $target->id);
        $attrs = array_merge(['status' => $status, 'deleted_at' => null], $attrs);

        if ($existing) {
            $existing->forceFill(array_merge(['user_id' => $lo, 'friend_id' => $hi], $attrs))->save();

            return $existing;
        }

        return Friendship::create(array_merge(['user_id' => $lo, 'friend_id' => $hi], $attrs));
    }
}
