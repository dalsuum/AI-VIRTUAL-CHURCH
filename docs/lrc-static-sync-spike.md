# Architecture Spike: JSON-Backed Synchronized Lyrics (LRC)

> Status: **spike / scoping** — not yet implemented. Captures the agreed
> boundaries (no DB schema, static audio only) before any code is written.

## Objective

Line-by-line synchronized lyrics in the service player. No relational schema
changes; synchronization applies **exclusively to static audio assets** and
degrades gracefully to plain text for generative/dynamic sources.

## Core Architectural Decisions

### 1. Storage — JSON-backed, keyed by slug

Timings live inside the existing hymn libraries, keyed by slug, **next to the
lyrics that already live there**. No `hymns` or `hymn_lyric_timings` DB table.

```json
"td-kumpipa-bia-un": {
  "title": "Kumpipa bia un",
  "lyrics": "Line 1\nLine 2",
  "timings": [
    { "time": 0.00, "line_index": 0 },
    { "time": 14.50, "line_index": 1 }
  ]
}
```

**⚠️ Storage shape is NOT uniform across languages — verified:**

- **Burmese / Tedim** → `workers/data/hymns_my.json`, `hymns_td.json` (a
  `{"hymns": [ ... ]}` array of objects). Timings attach cleanly per object.
- **English** → there is **no `hymns.json`**. English hymns are a Python list
  `HYMNS` in [hymns.py](../workers/hymns.py#L22). v1 options: (a) **defer
  English** (it already has the richest experience), or (b) add an inline
  `"timings"` key to each `HYMNS` dict. Recommend (a) for the pilot.

Timings must also bind to a **specific** audio file. A hymn can have multiple
static renders (`{slug}.sung.mp3` vs the instrumental `{slug}.mp3` / aligned
`inst/<NORM>.mp3`) whose durations differ — so a single `timings` array is only
valid for one of them. v1 scopes timings to the **sung** recording
(`hymn_sung`); instrumental sync is a follow-up keyed separately if wanted.

### 2. Scope restriction — static audio only

Synchronized playback is incompatible with per-request generative audio (Suno,
`local_ai`/MusicGen) and externally-hosted YouTube embeds. LRC is processed
**only** when the orchestrator resolves a `hymn_sung` (or later `hymn`) result
with a matching `timings` array.

If a hymn has no `timings`, or the source is dynamic, `ServicePlayer.vue`
falls back to its **current** behavior. Note this is not greenfield: the player
already wires `@timeupdate="onMediaTimeUpdate"` on `<audio>` and does
*proportional* `currentTime`→word-index highlighting today
([ServicePlayer.vue](../frontend/src/components/ServicePlayer.vue#L305)). LRC
**upgrades that one branch** from proportional estimation to real line
timestamps when `timings` is present; everything without timings keeps the
existing proportional path.

### 3. Delivery path — how timings reach the player

The worker posts hymn lyrics onto `service_assets` (carried in the asset's
`lyrics`/`text_payload`). For LRC, the resolved hymn's `timings` array rides
the same asset payload (a new optional field), so **no new API or table** — it
flows through the existing webhook contract the same way `lyrics` does.

### 4. The "Tapper" utility

Lightweight tool to capture timestamps: stream the local MP3, tap a key
(spacebar) at each line boundary, emit a `timings` array merged into the target
`hymns_*.json`.

- **Input:** target slug, the localized lyric lines, the local MP3.
- **Output:** `timings: [{time, line_index}]` written into the hymn object.

### 5. Frontend playback engine

- Drive off the native `<audio>` `timeupdate` event already in place — **no
  `setInterval` polling**.
- When `timings` exists: binary-search `currentTime` against it to set the
  active `line_index`, smooth-scroll the active line into view.
- When absent: unchanged proportional highlighting.

## Recommended Build: Tapper as a standalone Python CLI

**Recommendation: standalone Python CLI in `workers/tools/`, not a Laravel/Vue
admin component.** Rationale:

- The audio MP3s **and** the JSON libraries both already live under `workers/`.
  A CLI reads the MP3 from storage and writes `timings` straight into
  `hymns_*.json` in one process — zero plumbing.
- It matches every other dev tool here (`seed_tedim_midi.py`,
  `align_td_midi.py`, the seeders) — decoupled from the web app, no auth
  surface, no deploy gating.
- A web Tapper would need: audio served to the browser, admin-auth, a
  write-back API endpoint, and a merge/commit flow back into the JSON — i.e. the
  exact relational/API surface this spike set out to avoid.

Trade-off: a CLI is developer-only (no non-technical admin self-service). Given
timings are authored **once** per static hymn and then committed as config
(exactly like `td_midi_slug_map.json`), one-time CLI authoring is the right cost
profile. If non-technical authoring is later required, wrap the same CLI output
format in a web tool then.

## Open Questions

- **Which render do timings bind to** — sung vs instrumental? (v1: sung only.)
- **English** — defer, or inline `timings` into the `HYMNS` Python list?
- **Asset payload** — confirm the `service_assets`/webhook contract can carry an
  optional `timings` field without a migration (it currently carries `lyrics`).
- **Authoring effort** — timings are manual per hymn; pilot a handful of
  high-frequency Tedim/Burmese sung hymns before bulk authoring.
