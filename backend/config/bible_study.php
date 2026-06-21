<?php

return [

    /*
     * AI Bible Study module key (matches module_manifests.key and the worker
     * plugin). Used to scope personas, templates, memory, and the tool allow-list.
     */
    'module' => 'bible_study',

    /*
     * Redis list the composed discussion job is pushed onto. The bridge consumer
     * (workers/bridge.py) BLPOPs it and dispatches tasks.study_discuss.
     */
    'queue' => 'ai:study',

    /*
     * Live-stream (SSE) guards.
     *  idle_ttl           — seconds of inactivity before a session's stream token
     *                       expires (defence-in-depth beyond rotate-on-close).
     *  max_concurrent     — hard cap on simultaneous open streams PER USER, so a
     *                       user (or runaway mobile tabs) can't exhaust PHP-FPM.
     *  stream_max_seconds — a single SSE connection is closed after this long; the
     *                       client transparently reconnects + replays by seq.
     *  heartbeat_seconds  — comment ping cadence so proxies don't time the stream out.
     *  replay_max_gap     — largest after_seq gap a replay request may span.
     */
    'idle_ttl'           => (int) env('STUDY_IDLE_TTL', 1200),
    'max_concurrent'     => (int) env('STUDY_MAX_CONCURRENT', 2),
    'stream_max_seconds' => (int) env('STUDY_STREAM_MAX_SECONDS', 600),
    'heartbeat_seconds'  => (int) env('STUDY_HEARTBEAT_SECONDS', 15),
    'replay_max_gap'     => (int) env('STUDY_REPLAY_MAX_GAP', 10000),

    /*
     * Worker turn webhook HMAC tolerance (seconds) — rejects replayed payloads.
     */
    'webhook_tolerance' => (int) env('STUDY_WEBHOOK_TOLERANCE', 60),
];
