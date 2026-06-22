# SessionStateStore — unified session state engine

## Why

Five modules were independently reinventing "session state": Bible Study (structured
multi-round), Worship Service (linear resumable flow), Music (temporal playback), Journal
(derived snapshot), Pastor Chat (evolving message log). Left separate, this becomes *five
definitions of session correctness* and expensive-to-debug drift between DB, worker, and AI
memory. `SessionStateStore` makes one model:

> **Session = Graph (nodes) + Active Pointer (active_node_id) + Checkpoints.**

## Non-negotiable design rules

1. **`session_nodes` is the only *durable* truth for session state.** Streams (Redis SSE)
   and history mirrors are **derived observers** — they replay *from* nodes and must never
   define state. (This preserves the existing fail-soft mirror philosophy: a mirror failure
   can't corrupt truth because it isn't truth.)
2. **No module persists session state independently of the store.** Bypassing it
   reintroduces fragmentation.
3. Ephemeral transport (the live Redis event log) is explicitly *exempt* from rule 1 — it
   carries in-flight tokens, then nodes are the durable record.

## Data model (evolves the existing spine — no parallel `sessions` table)

`chat_sessions` **is** the session. We add graph columns rather than a second table:

```
chat_sessions  (+ root_session_id, parent_session_id, parent_node_id, active_node_id)
session_nodes  (id uuid, session_id, parent_node_id, branch_id, seq, type,
                sender, content[encrypted], metadata json, token_usage, created_at)
session_checkpoints (id, session_id, node_id, state_blob[encrypted], created_at)
```

`session_nodes` is a **superset of messages** — a message is just `type=message`. The
graph gives branching; the **`(branch_id, seq)`** pair keeps the *active linear branch* a
single indexed range scan (the same shape as `study_messages(session_id, turn)` today), so
the common read stays O(1)-indexed even at millions of rows. Pure parent-pointer walking was
rejected for being O(n) per render.

Self-referential pointers (`active_node_id`, `parent_node_id`) are plain indexed UUID
columns with **no FK constraint** — node→session and session→node would otherwise form a
circular FK. `parent_node_id` may cross sessions (that is how a fork's first node points
back into its parent).

## Interface

```php
interface SessionStateStore {
    public function get(string $sessionId): SessionState;                       // session + active branch
    public function appendNode(string $sessionId, SessionNodeData $node): string; // returns node id
    public function fork(string $sessionId, string $fromNodeId): string;        // returns new session id
    public function checkpoint(string $sessionId, string $nodeId, array $state): string;
    public function resume(string $sessionId): SessionState;                    // active branch + latest checkpoint
}
```

- **appendNode**: inherits the active node's `branch_id` and `seq+1`; if the active node is
  in *another* session (a fresh fork) it starts a new `branch_id` at `seq=1` with
  `parent_node_id = active`. Updates `chat_sessions.active_node_id`. Runs in a DB transaction.
- **fork**: new `chat_session` row with `root_session_id` (lineage root), `parent_session_id`,
  `parent_node_id`, and `active_node_id = fromNodeId`. No node copying — lineage is explicit.
- **checkpoint / resume**: checkpoints are a separate table keyed by `node_id`; `resume`
  returns the active branch plus the latest checkpoint blob (what Study/Music/Service rehydrate).

## How each module maps (target state)

| Module | Nodes | Checkpoints |
|---|---|---|
| Pastor Chat | each turn = `message` node | (none needed) |
| Bible Study | pastor/synthesis turns = `message`; each round = `checkpoint`/`system_event` | round/engine state |
| Worship Service | milestone chain = `system_event` nodes | service-state milestones |
| Music | playback events | `{track_id, position, queue, shuffle, volume}` |
| Journal | — | **consumer** of checkpoints, never a driver |

## Phased, non-breaking rollout

- **Phase 1 (this change):** add the columns/tables; introduce `SessionStateStore`; route
  message writes through it as a **dual-write** alongside the legacy `chat_messages`
  projection. **Read path unchanged.** Pastor Chat (the only live chat module) writes nodes;
  the assistant-reply webhook does too.
- **Phase 2:** begin writing `session_nodes` for Study/Service/Music + checkpoints.
- **Phase 3:** switch read paths to nodes.
- **Phase 4:** deprecate legacy linear-message assumptions and tables.

Phase 1 is additive and reversible: nodes are written but nothing reads them yet, so the
live product is unaffected while the new truth source backfills naturally.
