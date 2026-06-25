<?php

namespace App\Services\Pipeline\Worship;

use App\Jobs\DispatchServiceJob;
use App\Models\ServiceIntake;
use App\Models\ServiceSession;
use App\Models\ServiceSessionMeta;
use App\Models\Setting;
use App\Notifications\ServiceScheduledNotification;
use App\Services\CrisisInterceptService;
use App\Services\GuestUsageService;
use App\Services\HistoryService;
use App\Services\Pipeline\AiServicePipeline;
use App\Services\Pipeline\PipelineResult;
use App\Services\TokenService;
use App\Services\UsageLogger;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/**
 * Worship-service intake as a pipeline. Behaviour is identical to the former
 * ServiceController::intake; the ordering (charge before enrichment) and isolation are now
 * enforced by AiServicePipeline rather than by hand. Resolve via the container and pass the
 * route token: `app(WorshipServicePipeline::class)->forToken($token)->handle($request)`.
 */
final class WorshipServicePipeline extends AiServicePipeline
{
    private string $token;
    private ServiceSession $session;
    /** @var array<string, mixed> */
    private array $data = [];
    private ?string $contactEmail = null;
    private ?string $notifyEmail = null;

    public function __construct(
        private CrisisInterceptService $crisisService,
        private TokenService $tokens,
        private GuestUsageService $guests,
        private UsageLogger $usage,
        private HistoryService $history,
    ) {}

    public function forToken(string $token): self
    {
        $this->token = $token;

        return $this;
    }

    protected function prepare(Request $request): void
    {
        $this->session = ServiceSession::where('session_token', $this->token)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $this->data = $request->validate([
            'mood'          => ['required', 'string', 'max:100'],
            // Service language ('en' | 'my' | 'td'); locked per session like music_source.
            'language'      => ['nullable', 'string', 'in:en,my,td'],
            'custom_mood'   => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z]+$/'],
            'prayer_text'   => ['nullable', 'string', 'max:5000'],
            'scheduled_at'  => ['nullable', 'date', 'after:now'],
            'contact_email' => ['nullable', 'email', 'max:255'],
        ]);

        // A future time is only honoured while scheduling is enabled; otherwise the
        // service begins now (the UI hides the option, this guards direct calls).
        if (! empty($this->data['scheduled_at']) && ! Setting::schedulingEnabled()) {
            unset($this->data['scheduled_at']);
        }

        $user = $request->user();
        $this->contactEmail = $this->data['contact_email'] ?? null;
        $this->notifyEmail  = $this->contactEmail
            ?: (str_ends_with($user->email, '@guest.local') ? null : $user->email);

        if (! empty($this->data['scheduled_at']) && ! $this->notifyEmail) {
            throw new HttpResponseException(response()->json([
                'message' => 'Please provide your email so we can send you a reminder when your service begins.',
            ], 422));
        }

        // Lock the service language now, alongside the already-locked music source.
        $this->session->update(['language' => $this->data['language'] ?? 'en']);
    }

    protected function crisis(Request $request): ?PipelineResult
    {
        $check = $this->crisisService->inspect($this->session->session_token, $this->data['prayer_text'] ?? null);

        if ($check['intercepted']) {
            $this->session->update(['status' => 'abandoned']);

            return new PipelineResult(
                ['intercepted' => true, 'resource' => $check['resource']],
                200,
                runHooks: false,
            );
        }

        return null;
    }

    /** Worship services are charged at commit (spend), not reserved; no ticket. */
    protected function reserveQuota(Request $request): mixed
    {
        return null;
    }

    protected function execute(Request $request): PipelineResult
    {
        $intake = ServiceIntake::updateOrCreate(
            ['session_id' => $this->session->id],
            [
                'mood'        => $this->data['mood'],
                'custom_mood' => $this->data['custom_mood'] ?? null,
                'prayer_text' => $this->data['prayer_text'] ?? null,
            ],
        );

        // Scheduled for later: hold it for the scheduler (see DispatchDueServices).
        if (! empty($this->data['scheduled_at'])) {
            $this->session->update([
                'status'        => 'scheduled',
                'scheduled_at'  => $this->data['scheduled_at'],
                'contact_email' => $this->contactEmail,
            ]);

            return new PipelineResult([
                'intercepted'   => false,
                'session_token' => $this->session->session_token,
                'intake_id'     => $intake->id,
                'status'        => 'scheduled',
                'scheduled_at'  => $this->session->scheduled_at?->toIso8601String(),
            ], 202);
        }

        // Guard: if this session already triggered the pipeline, don't burn a second GPU job.
        if (in_array($this->session->status, ['active', 'processing', 'complete'])) {
            return new PipelineResult([
                'intercepted'   => false,
                'session_token' => $this->session->session_token,
                'intake_id'     => $intake->id,
                'status'        => $this->session->status,
            ], 202);
        }

        $this->session->update(['status' => 'active', 'scheduled_at' => null]);
        // afterCommit so the worker never picks up the job before this transaction commits.
        DispatchServiceJob::dispatch($this->session->id)->afterCommit();

        return new PipelineResult([
            'intercepted'   => false,
            'session_token' => $this->session->session_token,
            'intake_id'     => $intake->id,
            'status'        => 'active',
        ], 202);
    }

    /**
     * Members/premium spend a token; guests record their single free use. Idempotent per
     * session via the `service:{id}` reference, so a retried intake never double-charges.
     */
    protected function commitQuota(Request $request, mixed $ticket): void
    {
        $user = $request->user();

        if ($user->isGuestAccount()) {
            $this->guests->record($request, 'service');

            return;
        }

        $already = $user->tokenLedger()
            ->where('reference', "service:{$this->session->id}")
            ->whereIn('type', ['spend', 'reservation'])
            ->exists();
        if (! $already) {
            $this->tokens->spend($user, 'service', "service:{$this->session->id}");
        }

        $this->usage->record($user, 'service', 'ok', $this->tokens->cost('service'), "service:{$this->session->id}");
    }

    protected function hooks(Request $request): array
    {
        return [
            // History mirror — best-effort, idempotent per service_session.
            function (Request $request, PipelineResult $result): void {
                if (ServiceSessionMeta::where('service_session_id', $this->session->id)->exists()) {
                    return;
                }
                $chat = $this->history->startSession($request->user(), 'service', [
                    'language' => $this->session->language,
                    'mood'     => $this->data['mood'],
                    'title'    => ucwords($this->data['mood']) . ' Service',
                ]);
                ServiceSessionMeta::create([
                    'chat_session_id'    => $chat->id,
                    'service_session_id' => $this->session->id,
                    'service_name'       => ucwords($this->data['mood']) . ' Worship Service',
                ]);
            },

            // Scheduled-booking confirmation email — best-effort notification.
            function (Request $request, PipelineResult $result): void {
                if (($result->payload['status'] ?? null) === 'scheduled' && $this->notifyEmail) {
                    Notification::route('mail', $this->notifyEmail)
                        ->notify(new ServiceScheduledNotification(
                            $this->session,
                            $request->user()->name,
                            $this->notifyEmail,
                        ));
                }
            },
        ];
    }
}
