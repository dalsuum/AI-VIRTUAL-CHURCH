<?php

namespace App\Services\SessionState;

use App\Models\ChatSession;
use App\Models\SessionCheckpoint;
use Illuminate\Support\Collection;

/**
 * The reconstructed state of a session: the session row, the nodes of its ACTIVE branch
 * in order, and the latest checkpoint (if any). This is what `get()` / `resume()` return —
 * the single answer to "what is the current state of this session?".
 */
final class SessionState
{
    /** @param Collection<int,\App\Models\SessionNode> $activeBranch */
    public function __construct(
        public readonly ChatSession $session,
        public readonly Collection $activeBranch,
        public readonly ?SessionCheckpoint $latestCheckpoint = null,
    ) {}

    public function activeNodeId(): ?string
    {
        return $this->session->active_node_id;
    }
}
