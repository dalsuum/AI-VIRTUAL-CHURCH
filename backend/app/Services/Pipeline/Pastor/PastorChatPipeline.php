<?php

namespace App\Services\Pipeline\Pastor;

use App\Models\ChatSession;
use App\Services\GuestUsageService;
use App\Services\HistoryService;
use App\Services\PastorReplyDispatcher;
use App\Services\Pipeline\AiServicePipeline;
use App\Services\Pipeline\PipelineResult;
use App\Services\TokenService;
use App\Services\UsageLogger;
use Illuminate\Http\Request;

/**
 * AI Pastor Chat start as a pipeline. Same behaviour as the former
 * PastorChatController::start. Here the chat session IS the primary entity (history-native,
 * not a mirror), so there is no post-commit history hook. Uses the reserve→dispatch→commit
 * saga (usesTransaction=false): the worker is fed by a raw Redis push.
 */
final class PastorChatPipeline extends AiServicePipeline
{
    private $user;
    private ChatSession $session;
    private string $message = '';
    private string $plaintextToken = '';

    public function __construct(
        private HistoryService $history,
        private TokenService $tokens,
        private GuestUsageService $guests,
        private UsageLogger $usage,
        private PastorReplyDispatcher $replies,
    ) {}

    protected function usesTransaction(): bool
    {
        return false;
    }

    protected function prepare(Request $request): void
    {
        $this->user = $request->user();
        $data = $request->validate([
            'message'  => ['required', 'string', 'max:4000'],
            'language' => ['nullable', 'string', 'max:12'],
        ]);
        $this->message = trim($data['message']);

        $this->session = $this->history->startSession($this->user, 'pastor', [
            'language' => $data['language'] ?? ($this->user->fav_language ?? 'en'),
        ]);
        $this->plaintextToken = $this->session->issueStreamToken();
        $this->session->save();
    }

    protected function reserveQuota(Request $request): mixed
    {
        return $this->user->isGuestAccount()
            ? null
            : $this->tokens->reserve($this->user, 'pastor', "pastor:{$this->session->id}");
    }

    protected function execute(Request $request): PipelineResult
    {
        $this->history->recordMessage($this->session, 'user', $this->message);
        $this->replies->dispatch($this->session);

        return new PipelineResult([
            'session'      => ['id' => $this->session->id, 'language' => $this->session->language],
            'stream_token' => $this->plaintextToken,   // returned ONCE
        ], 201);
    }

    protected function rollbackQuota(mixed $ticket): void
    {
        if ($ticket) {
            $this->tokens->rollback($ticket);
        }
        $this->usage->record($this->user, 'pastor', 'failed', 0, "pastor:{$this->session->id}");
    }

    protected function commitQuota(Request $request, mixed $ticket): void
    {
        if ($ticket) {
            $this->tokens->commit($ticket);
        } elseif ($this->user->isGuestAccount()) {
            $this->guests->record($request, 'pastor');
        }

        $this->usage->record($this->user, 'pastor', 'ok', $ticket?->amount ?? 0, "pastor:{$this->session->id}");
    }
}
