<?php

namespace App\Services;

use App\Models\UsageLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Writes per-request rows to usage_logs for cost forensics. Both successful and failed
 * AI requests are logged (ops teams rely on the failures). Never throws — a logging
 * failure must not break the request it's recording.
 */
class UsageLogger
{
    public function record(?User $user, string $service, string $status, int $tokens = 0, ?string $requestId = null, ?string $model = null, int $costMicros = 0, ?int $latencyMs = null): void
    {
        try {
            UsageLog::create([
                'user_id'     => $user?->id,
                'guest_ref'   => $user?->isGuestAccount() ? 'guest' : null,
                'service'     => $service,
                'model'       => $model,
                'tokens'      => $tokens,
                'cost_micros' => $costMicros,
                'latency_ms'  => $latencyMs,
                'status'      => $status,
                'request_id'  => $requestId,
                'created_at'  => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('usage_logs write failed', ['service' => $service, 'error' => $e->getMessage()]);
        }
    }
}
