<?php

namespace App\Services\Pipeline;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single enforcement boundary for every AI service endpoint (worship intake, Bible study,
 * Pastor chat). Subclasses declare *what* each phase does; this base owns the *order* and
 * the failure containment, so a controller can never reorder the critical steps again.
 *
 * Execution model (see docs/service-execution-pipeline.md):
 *
 *   HARD PATH — any failure aborts the request:
 *     1. prepare()       validate input, load/create the primary entity (pre-transaction)
 *     2. crisis()        safety gate; may short-circuit with a non-error response and NO
 *                        charge. Kept pre-transaction because it is a network call (LLM)
 *                        and must not hold a DB transaction open.
 *     3. [transaction]   reserveQuota() → execute() → commitQuota(), all-or-nothing. The
 *                        quota write is the one irreversible side-effect and commits
 *                        atomically with the primary persistence. On execute() failure the
 *                        reservation is rolled back.
 *
 *   SOFT PATH — best-effort, post-commit, isolated:
 *     4. hooks()         history mirror, notifications, analytics, … Each runs in its own
 *                        try/catch: a hook failure can never roll back committed work,
 *                        break a sibling hook, or change the user response.
 *
 * Controllers shrink to: `return app(XxxPipeline::class)->handle($request);`
 */
abstract class AiServicePipeline
{
    /** Validate input and load/create the primary entity. Throw to abort (404/422/…). */
    abstract protected function prepare(Request $request): void;

    /**
     * Optional safety gate. Return a PipelineResult to short-circuit (no quota charge,
     * no primary action), or null to proceed. Runs in the hard path, before the action.
     */
    protected function crisis(Request $request): ?PipelineResult
    {
        return null;
    }

    /** Reserve quota (member token). Return a ticket the executor commits, or null (guests). */
    abstract protected function reserveQuota(Request $request): mixed;

    /** Execute the irreversible primary action. Return the response payload + status. */
    abstract protected function execute(Request $request): PipelineResult;

    /** Commit quota after a successful action (token commit / guest single-use record). */
    abstract protected function commitQuota(Request $request, mixed $ticket): void;

    /** Roll back a reservation when the primary action failed. No-op by default. */
    protected function rollbackQuota(mixed $ticket): void {}

    /**
     * Whether to wrap reserveQuota→execute→commitQuota in a single DB transaction.
     *
     * True (default) suits "charge at commit" endpoints whose primary action enqueues via
     * Laravel's queue with ->afterCommit() (e.g. worship). Override to FALSE for the
     * reserve→dispatch→commit *saga* used by endpoints that hand work to external workers
     * by pushing straight onto a Redis list (study, pastor): there the reservation — not a
     * transaction — is what keeps quota correct if dispatch fails, and a transaction would
     * let a worker read rows that haven't committed yet.
     */
    protected function usesTransaction(): bool
    {
        return true;
    }

    /**
     * Best-effort, post-commit enrichment. Subclasses (not controllers) register hooks
     * here so registration can't drift per call site.
     *
     * @return array<callable(Request, PipelineResult): void>
     */
    protected function hooks(Request $request): array
    {
        return [];
    }

    final public function handle(Request $request): JsonResponse
    {
        // Hard path, pre-transaction: validation + safety. Either may short-circuit
        // (404/422/crisis intercept) without opening a transaction or charging quota.
        $this->prepare($request);

        if ($intercept = $this->crisis($request)) {
            return response()->json($intercept->payload, $intercept->status);
        }

        // Hard path: reserve → execute → commit. Wrapped in a transaction for "charge at
        // commit" pipelines; run as a reserve/commit saga (no transaction) for pipelines
        // that dispatch to external workers via raw Redis pushes. See usesTransaction().
        $core = function () use ($request) {
            $ticket = $this->reserveQuota($request);

            try {
                $outcome = $this->execute($request);
            } catch (\Throwable $e) {
                $this->rollbackQuota($ticket);
                throw $e;
            }

            $this->commitQuota($request, $ticket);

            return $outcome;
        };

        $result = $this->usesTransaction() ? DB::transaction($core) : $core();

        // Soft path: post-commit, isolated. Skipped for short-circuit results.
        if ($result->runHooks) {
            foreach ($this->hooks($request) as $hook) {
                try {
                    $hook($request, $result);
                } catch (\Throwable $e) {
                    Log::warning('Pipeline post-commit hook failed', [
                        'pipeline' => static::class,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }
        }

        return response()->json($result->payload, $result->status);
    }
}
