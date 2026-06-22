<?php

namespace App\Http\Controllers;

use App\Models\MusicTrack;
use App\Models\ServiceAsset;
use App\Models\ServiceSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Internal endpoint hit by the Python workers. Protected by a shared secret header
 * (WORKER_WEBHOOK_SECRET) rather than user auth, since no user is in the loop here.
 */
class WebhookController extends Controller
{
    public function assetReady(Request $request): JsonResponse
    {
        $secret = (string) config('services.worker.secret', '');
        abort_unless(
            strlen($secret) >= 32 && hash_equals($secret, (string) $request->header('X-Worker-Secret')),
            403
        );

        $data = $request->validate([
            'session_token' => ['required', 'string'],
            'segment'       => ['required', 'string'],
            // Nullable: a narration-only pass enriches an existing row's audio_key
            // without restating (or clobbering) the segment's primary asset_type.
            'asset_type'    => ['nullable', 'in:video,audio,text,url,youtube'],
            'storage_key'   => ['nullable', 'string'],
            'audio_key'     => ['nullable', 'string'],
            'provider_ref'  => ['nullable', 'string'],
            'text_payload'  => ['nullable', 'string'],
            // Public-domain hymn verses to show on screen (hymn music sources).
            'lyrics'        => ['nullable', 'string'],
            // Optional LRC line timings ([{time, line_index}]) paired with `lyrics`.
            'timings'           => ['nullable', 'array'],
            'timings.*.time'       => ['required_with:timings', 'numeric'],
            'timings.*.line_index' => ['required_with:timings', 'integer', 'min:0'],
        ]);

        $session = ServiceSession::where('session_token', $data['session_token'])->firstOrFail();

        // A segment can arrive in several passes: first its text, then (optionally) an
        // avatar video and/or text-to-speech narration of that text. Each pass must
        // enrich the row, not erase the others — so absent fields fall back to
        // whatever is already stored.
        $existing = ServiceAsset::firstWhere([
            'session_id' => $session->id,
            'segment'    => $data['segment'],
        ]);

        $asset = ServiceAsset::updateOrCreate(
            ['session_id' => $session->id, 'segment' => $data['segment']],
            [
                'asset_type'   => $data['asset_type']   ?? $existing?->asset_type ?? 'audio',
                'storage_key'  => $data['storage_key']  ?? $existing?->storage_key,
                'audio_key'    => $data['audio_key']    ?? $existing?->audio_key,
                'provider_ref' => $data['provider_ref'] ?? $existing?->provider_ref,
                'text_payload' => $data['text_payload'] ?? $existing?->text_payload,
                'lyrics'       => $data['lyrics']       ?? $existing?->lyrics,
                'timings'      => $data['timings']      ?? $existing?->timings,
                'status'       => 'ready',
                'ready_at'     => now(),
            ],
        );

        // Backfill scripture_ref into the intake the first time the scripture segment
        // arrives. The worker stores "{ref}\n\n{verse text}" in text_payload; the first
        // line is the reference (e.g. "Psalm 23:1-4"). We only write if the intake has
        // no ref yet so a re-run of the scripture segment doesn't clobber a manual value.
        if ($data['segment'] === 'scripture' && !empty($data['text_payload'])) {
            $ref = trim(explode("\n", $data['text_payload'])[0]);
            $intake = $session->intake;
            if ($ref && $intake && !$intake->scripture_ref) {
                $intake->update(['scripture_ref' => $ref]);
            }
        }

        // Broadcast to the client over WebSockets (Laravel Reverb / Echo).
        // event(new AssetReady($session->session_token, $asset));

        // Myanmar/Tedim services are now generated directly in their target language
        // by the Python worker. Do not dispatch the old post-generation localization
        // jobs here: they can run for many minutes and block DispatchServiceJob on the
        // single Laravel queue, leaving new services with no pages. Keep the status
        // markers ready for older UI/admin code that still reads them.
        if ($session->language === 'td' && $session->tedim_status === null) {
            $session->update(['tedim_status' => 'ready']);
        }

        if ($session->language === 'my' && $session->burmese_status === null) {
            $session->update(['burmese_status' => 'ready']);
        }

        return response()->json(['ok' => true, 'asset_id' => $asset->id]);
    }

    /**
     * Register a freshly composed Suno track in the reusable, language-and-mood-keyed pool. The
     * worker calls this once per fresh generation; deduped by provider_ref so a
     * retried task can't double-insert. Reused tracks already exist here, so they
     * aren't re-posted.
     */
    public function musicTrack(Request $request): JsonResponse
    {
        $secret = (string) config('services.worker.secret', '');
        abort_unless(
            strlen($secret) >= 32 && hash_equals($secret, (string) $request->header('X-Worker-Secret')),
            403
        );

        $data = $request->validate([
            'mood'         => ['required', 'string', 'max:100'],
            'language'     => ['nullable', 'string', 'max:5'],
            'provider_ref' => ['required', 'string'],
            'storage_key'  => ['required', 'string'],   // RAW object key, not a presigned URL
            'title'        => ['nullable', 'string'],
            'lyrics'       => ['nullable', 'string'],
        ]);

        $language = in_array($data['language'] ?? 'en', ['en', 'my', 'td'], true)
            ? ($data['language'] ?? 'en')
            : 'en';

        $track = MusicTrack::updateOrCreate(
            ['provider_ref' => $data['provider_ref']],
            [
                'mood'        => $data['mood'],
                'language'    => $language,
                'storage_key' => $data['storage_key'],
                'title'       => $data['title'] ?? null,
                'lyrics'      => $data['lyrics'] ?? null,
                'source'      => 'suno',
            ],
        );

        return response()->json(['ok' => true, 'track_id' => $track->id]);
    }

    /**
     * Persist one completed Bible Study turn (source of truth for show/replay) and
     * its token usage. HMAC-signed (over "{ts}.{body}") with a ±tolerance window so a
     * leaked secret can't replay old payloads. The live event stream is published
     * separately by the worker; this only writes durable state.
     */
    public function studyTurn(Request $request): JsonResponse
    {
        $this->verifySignature($request);

        $data = $request->validate([
            'session_id'        => ['required', 'integer'],
            'turn'              => ['required', 'integer', 'min:1'],
            'role'              => ['required', 'in:user,moderator,pastor,synthesis,system'],
            'persona_id'        => ['nullable', 'integer'],
            'display_name'      => ['nullable', 'string', 'max:120'],
            'content'           => ['nullable', 'string'],
            'scripture_refs'    => ['nullable', 'array'],
            'safety_flag'       => ['nullable', 'boolean'],
            'prompt_tokens'     => ['nullable', 'integer'],
            'completion_tokens' => ['nullable', 'integer'],
        ]);

        $session = \App\Models\StudySession::findOrFail($data['session_id']);

        \App\Models\StudyMessage::updateOrCreate(
            ['session_id' => $session->id, 'turn' => $data['turn']],
            [
                'role'              => $data['role'],
                'persona_id'        => $data['persona_id'] ?? null,
                'content'           => $data['content'] ?? '',
                'scripture_refs'    => $data['scripture_refs'] ?? null,
                'safety_flag'       => (bool) ($data['safety_flag'] ?? false),
                'prompt_tokens'     => $data['prompt_tokens'] ?? null,
                'completion_tokens' => $data['completion_tokens'] ?? null,
            ],
        );

        if (($data['prompt_tokens'] ?? 0) || ($data['completion_tokens'] ?? 0)) {
            \App\Models\AiUsageLedger::create([
                'module'            => config('bible_study.module'),
                'session_id'        => $session->id,
                'prompt_tokens'     => $data['prompt_tokens'] ?? 0,
                'completion_tokens' => $data['completion_tokens'] ?? 0,
            ]);
        }

        $session->update(['last_activity_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** Persist the generated end-of-discussion summary. HMAC-signed like studyTurn. */
    public function studySummary(Request $request): JsonResponse
    {
        $this->verifySignature($request);

        $data = $request->validate([
            'session_id' => ['required', 'integer'],
            'summary'    => ['required', 'array'],
        ]);

        $session = \App\Models\StudySession::findOrFail($data['session_id']);
        $s = $data['summary'];

        \App\Models\StudySummary::updateOrCreate(
            ['session_id' => $session->id],
            [
                'key_verses'           => $s['key_verses'] ?? null,
                'lessons'              => $s['lessons'] ?? null,
                'prayer'               => is_string($s['prayer'] ?? null) ? $s['prayer'] : null,
                'action_points'        => $s['action_points'] ?? null,
                'reflection_questions' => $s['reflection_questions'] ?? null,
                'study_plan'           => $s['study_plan'] ?? null,
                'generated_at'         => now(),
            ],
        );

        $session->update(['state' => 'summarized']);

        // Mirror a short summary onto the bridged unified-history row, if any.
        $bridge = \App\Models\BibleSessionMeta::where('study_session_id', $session->id)->first();
        if ($bridge && $bridge->chat_session_id) {
            $lessons = is_array($s['lessons'] ?? null) ? implode(' ', $s['lessons']) : ($s['lessons'] ?? '');
            $summary = trim((string) $lessons) ?: (is_string($s['prayer'] ?? null) ? $s['prayer'] : '');
            $bridge->update(['discussion_summary' => $summary]);
            if ($summary !== '') {
                \App\Models\ChatSession::whereKey($bridge->chat_session_id)
                    ->update(['summary' => mb_substr($summary, 0, 1000), 'status' => 'completed', 'ended_at' => now()]);
                \Illuminate\Support\Facades\Cache::forget(
                    'history:list:' . (\App\Models\ChatSession::find($bridge->chat_session_id)->user_id ?? 0)
                );
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Unified-history worker callback (HMAC-signed). Handles:
     *  - pastor_reply:   append the assistant turn + push an SSE event for the live stream
     *  - title_summary:  set the session title/summary + auto-tags + memory snapshot
     */
    public function historyCallback(Request $request): JsonResponse
    {
        $this->verifySignature($request);

        $data = $request->validate([
            'mode'             => ['required', 'in:pastor_reply,title_summary,journal'],
            'session_id'       => ['nullable', 'string'],
            'journal_entry_id' => ['nullable', 'integer'],
            'reply'            => ['nullable', 'string'],
            'title'            => ['nullable', 'string', 'max:200'],
            'summary'          => ['nullable', 'string'],
            'scripture_ref'    => ['nullable', 'string', 'max:120'],
            'insight'          => ['nullable', 'string'],
            'prayer'           => ['nullable', 'string'],
            'reflection'       => ['nullable', 'string'],
            'tags'             => ['nullable', 'array'],
            'tags.*'           => ['string', 'max:40'],
            'token_usage'      => ['nullable', 'integer'],
        ]);

        // Journal fills an entry by its own id, not a chat session.
        if ($data['mode'] === 'journal') {
            return $this->fillJournalEntry($data);
        }

        $session = \App\Models\ChatSession::find($data['session_id']);
        if (! $session) {
            return response()->json(['ok' => false], 404);
        }

        if ($data['mode'] === 'pastor_reply') {
            $reply = trim((string) ($data['reply'] ?? ''));
            if ($reply !== '') {
                \App\Models\ChatMessage::create([
                    'session_id'  => $session->id,
                    'sender'      => 'assistant',
                    'content'     => $reply,
                    'token_usage' => $data['token_usage'] ?? null,
                ]);
                $session->forceFill(['last_activity_at' => now()])->save();
                \Illuminate\Support\Facades\Cache::forget("history:list:{$session->user_id}");
                // Push to the live SSE tail (assistant message as a single event).
                \Illuminate\Support\Facades\Redis::rpush(
                    "pastor:{$session->id}:events",
                    json_encode(['type' => 'message', 'sender' => 'assistant', 'content' => $reply])
                );
            }
            // After enough turns, ask for a title/summary too.
            if ($session->title === null
                && \App\Models\ChatMessage::where('session_id', $session->id)->count() >= 3) {
                app(\App\Services\HistoryTitleService::class)->enqueue($session);
            }

            return response()->json(['ok' => true]);
        }

        // title_summary
        $update = [];
        if (! empty($data['title']))   { $update['title'] = $data['title']; }
        if (! empty($data['summary'])) { $update['summary'] = $data['summary']; }
        if ($update) {
            $session->update($update);
            \Illuminate\Support\Facades\Cache::forget("history:list:{$session->user_id}");
        }
        foreach (array_slice(array_unique($data['tags'] ?? []), 0, 20) as $tag) {
            \App\Models\ChatSessionTag::firstOrCreate(
                ['chat_session_id' => $session->id, 'tag' => $tag],
                ['auto' => true]
            );
        }
        if (! empty($data['summary'])) {
            \App\Models\AiMemory::create([
                'module'          => 'history',
                'chat_session_id' => $session->id,
                'user_id'         => $session->user_id,
                'kind'            => 'summary',
                'content'         => $data['summary'],
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /** Fill a pending journal entry with the worker-generated reflection. */
    private function fillJournalEntry(array $data): JsonResponse
    {
        $entry = \App\Models\JournalEntry::find($data['journal_entry_id'] ?? 0);
        if (! $entry) {
            return response()->json(['ok' => false], 404);
        }

        $hasContent = ! empty($data['insight']) || ! empty($data['prayer']) || ! empty($data['reflection']);
        $entry->update([
            'status'        => $hasContent ? 'ready' : 'failed',
            'title'         => $data['title'] ?: $entry->title,
            'scripture_ref' => $data['scripture_ref'] ?? null,
            'insight'       => $data['insight'] ?? null,
            'prayer'        => $data['prayer'] ?? null,
            'reflection'    => $data['reflection'] ?? null,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Verify an HMAC-signed worker payload: signature = HMAC-SHA256(secret,
     * "{timestamp}.{raw body}"), compared in constant time, with a tolerance window
     * to reject replays. Falls back to nothing — fail closed.
     */
    private function verifySignature(Request $request): void
    {
        $secret = (string) config('services.worker.secret', '');
        $ts     = (string) $request->header('X-Worker-Timestamp', '');
        $sig    = (string) $request->header('X-Worker-Signature', '');
        $tolerance = (int) config('bible_study.webhook_tolerance', 60);

        abort_unless(strlen($secret) >= 32, 403);
        abort_unless($ts !== '' && abs(time() - (int) $ts) <= $tolerance, 403, 'Stale or missing timestamp.');

        $expected = hash_hmac('sha256', $ts . '.' . $request->getContent(), $secret);
        abort_unless($sig !== '' && hash_equals($expected, $sig), 403, 'Bad signature.');
    }
}
