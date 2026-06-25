// Live discussion stream for AI Bible Study. Connects to the server-sent-events
// endpoint, which replays the durable event log from `after_seq` on every (re)open —
// so reconnection is idempotent: we track the highest seq seen and never re-apply an
// older one. EventSource can't set headers, so the hash-only stream token rides as a
// query param (the Sanctum session cookie still authenticates via withCredentials).
import { ref } from "vue";

const BASE_URL = import.meta.env.VITE_API_URL || "http://localhost:8000/api";

export function useStudyStream() {
  const connected = ref(false);
  const reconnecting = ref(false);

  let source = null;
  let lastSeq = 0;
  let closedByUser = false;
  let handlers = {};
  let sessionId = null;
  let token = null;
  let retryDelay = 1000;

  function open(id, streamToken, eventHandlers) {
    sessionId = id;
    token = streamToken;
    handlers = eventHandlers || {};
    closedByUser = false;
    _connect();
  }

  function _connect() {
    if (closedByUser) return;
    const url =
      `${BASE_URL}/v1/study/sessions/${sessionId}/stream` +
      `?token=${encodeURIComponent(token)}&after_seq=${lastSeq}`;
    source = new EventSource(url, { withCredentials: true });

    source.onopen = () => {
      connected.value = true;
      reconnecting.value = false;
      retryDelay = 1000;
    };

    source.onmessage = (e) => {
      let env;
      try {
        env = JSON.parse(e.data);
      } catch {
        return;
      }
      // Idempotency: order + dedupe on the monotonic per-session seq.
      if (typeof env.seq === "number") {
        if (env.seq <= lastSeq) return;
        lastSeq = env.seq;
      }
      const fn = handlers[env.event];
      if (fn) fn(env);
      if (handlers["*"]) handlers["*"](env);
    };

    source.onerror = () => {
      connected.value = false;
      if (source) source.close();
      if (closedByUser) return;
      // Reconnect with backoff; the server replays anything missed since lastSeq.
      reconnecting.value = true;
      setTimeout(_connect, retryDelay);
      retryDelay = Math.min(retryDelay * 2, 15000);
    };
  }

  function close() {
    closedByUser = true;
    connected.value = false;
    reconnecting.value = false;
    if (source) {
      source.close();
      source = null;
    }
  }

  return { connected, reconnecting, open, close };
}
