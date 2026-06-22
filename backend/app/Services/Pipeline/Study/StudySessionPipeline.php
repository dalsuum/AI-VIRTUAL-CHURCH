<?php

namespace App\Services\Pipeline\Study;

use App\Models\BibleSessionMeta;
use App\Models\ModuleManifest;
use App\Models\StudyMessage;
use App\Models\StudySession;
use App\Services\GuestUsageService;
use App\Services\HistoryService;
use App\Services\Pipeline\AiServicePipeline;
use App\Services\Pipeline\PipelineResult;
use App\Services\StudyDispatchService;
use App\Services\StudyInputGuard;
use App\Services\TokenService;
use App\Services\UsageLogger;
use Illuminate\Http\Request;

/**
 * AI Bible Study session start as a pipeline. Same behaviour as the former
 * StudyController::createSession, but the ordering + failure isolation are owned by
 * AiServicePipeline. Uses the reserve→dispatch→commit *saga* (usesTransaction=false): the
 * worker is fed by a raw Redis push, so the reservation — not a DB transaction — keeps the
 * token economy correct if dispatch fails.
 */
final class StudySessionPipeline extends AiServicePipeline
{
    private $user;
    private StudySession $session;
    private string $question = '';
    private string $plaintextToken = '';

    public function __construct(
        private StudyDispatchService $dispatch,
        private StudyInputGuard $guard,
        private TokenService $tokens,
        private GuestUsageService $guests,
        private UsageLogger $usage,
        private HistoryService $history,
    ) {}

    protected function usesTransaction(): bool
    {
        return false;
    }

    protected function prepare(Request $request): void
    {
        $this->user = $request->user();
        $this->question = trim($request->string('question'));

        [$ok, $reason] = $this->guard->check($this->question);
        abort_if(! $ok, 422, $reason);

        $manifest = ModuleManifest::where('key', config('bible_study.module'))->first();

        $session = new StudySession([
            'user_id'          => $this->user->id,
            'language'         => $request->string('language'),
            'translation'      => $request->string('translation'),
            'style'            => $request->input('style'),
            'topic'            => mb_substr($this->question, 0, 160),
            'agent_count'      => $manifest->clampAgentCount((int) $request->integer('agent_count')),
            'state'            => 'created',
            'contact_email'    => $request->input('contact_email'),
            'last_activity_at' => now(),
        ]);
        $this->plaintextToken = $session->issueStreamToken();
        $session->owner_fingerprint = StudySession::fingerprint(
            "u:{$this->user->id}", $request->userAgent(), $request->ip()
        );
        $session->save();

        StudyMessage::create([
            'session_id' => $session->id,
            'turn'       => 1,
            'role'       => 'user',
            'content'    => $this->question,
        ]);

        $this->session = $session;
    }

    /** Members/premium reserve a token (refunded if dispatch fails); guests have no wallet. */
    protected function reserveQuota(Request $request): mixed
    {
        return $this->user->isGuestAccount()
            ? null
            : $this->tokens->reserve($this->user, 'study', "study:{$this->session->id}");
    }

    protected function execute(Request $request): PipelineResult
    {
        $this->session->update(['state' => 'discussing']);
        $this->dispatch->dispatchRound($this->session, $this->question);

        return new PipelineResult([
            'session'      => $this->session->only(['id', 'language', 'translation', 'style', 'agent_count', 'state']),
            'stream_token' => $this->plaintextToken,   // returned ONCE — never stored in plaintext
        ], 201);
    }

    protected function rollbackQuota(mixed $ticket): void
    {
        if ($ticket) {
            $this->tokens->rollback($ticket);
        }
        $this->usage->record($this->user, 'study', 'failed', 0, "study:{$this->session->id}");
    }

    protected function commitQuota(Request $request, mixed $ticket): void
    {
        if ($ticket) {
            $this->tokens->commit($ticket);
        } elseif ($this->user->isGuestAccount()) {
            $this->guests->record($request, 'study');
        }

        $this->usage->record($this->user, 'study', 'ok', $ticket?->amount ?? 0, "study:{$this->session->id}");
    }

    protected function hooks(Request $request): array
    {
        return [
            // Mirror into the unified history spine so the study appears in the sidebar.
            // Best-effort: a failure here never affects the dispatched session or its quota.
            function (Request $request, PipelineResult $result): void {
                $chat = $this->history->startSession($this->user, 'bible_study', [
                    'language' => $this->session->language,
                    'title'    => mb_substr($this->question, 0, 120),
                    'mood'     => $this->session->mood,
                ]);
                BibleSessionMeta::create([
                    'chat_session_id'  => $chat->id,
                    'study_session_id' => $this->session->id,
                    'translation'      => $this->session->translation,
                ]);
            },
        ];
    }
}
