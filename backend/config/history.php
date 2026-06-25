<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Read from session_nodes (SessionStateStore Phase 3)
    |--------------------------------------------------------------------------
    | When true, history read endpoints (show / shared / export) derive a
    | session's messages from session_nodes — the durable truth — instead of
    | the legacy chat_messages projection. The Phase 1-2 dual-write keeps the
    | two equivalent, so flipping this off instantly reverts to the legacy
    | read path with no data change.
    */
    'read_from_nodes' => (bool) env('HISTORY_READ_FROM_NODES', true),
];
