<?php

namespace App\Services;

use App\Models\GuestTracking;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Enforces the guest "one free use per service" rule across cookie clears. We never
 * store raw identifiers — only salted SHA-256 hashes of the IP, a client-supplied
 * browser fingerprint, and a long-lived first-party cookie UUID. A visitor is matched
 * if the cookie matches OR the (IP + fingerprint) pair matches, so dropping any single
 * signal is not enough to reset the quota, while a shared church IP alone won't
 * false-block (it needs to coincide with the same fingerprint).
 */
class GuestUsageService
{
    private function salt(): string
    {
        return (string) config('app.key');
    }

    private function h(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : hash_hmac('sha256', $value, $this->salt());
    }

    /** [ip_hash, fingerprint_hash, cookie_hash] for the current request. */
    public function identify(Request $request): array
    {
        $fingerprint = (string) ($request->header('X-Guest-Fingerprint') ?? $request->input('fingerprint', ''));
        $cookie      = (string) ($request->cookie('guest_id') ?? '');

        return [
            $this->h($request->ip()),
            $this->h($fingerprint),
            $this->h($cookie),
        ];
    }

    /** Query for rows that plausibly belong to the same visitor (cookie OR ip+fingerprint). */
    private function matchQuery(?string $ip, ?string $fp, ?string $cookie)
    {
        return GuestTracking::query()
            ->when($cookie, fn ($q) => $q->orWhere('cookie_hash', $cookie))
            ->when($ip && $fp, fn ($q) => $q->orWhere(fn ($w) => $w->where('ip_hash', $ip)->where('fingerprint_hash', $fp)));
    }

    /** Rows that plausibly belong to the same visitor. */
    private function matches(?string $ip, ?string $fp, ?string $cookie)
    {
        return $this->matchQuery($ip, $fp, $cookie)->get();
    }

    /** Merge $service into a locked row's services_used map (idempotent per service). */
    private function mergeService(GuestTracking $row, string $service, ?string $fp, ?string $cookie): void
    {
        $used = $row->services_used ?? [];
        $used[$service] = $used[$service] ?? Carbon::now()->toIso8601String();

        $row->update([
            'services_used'    => $used,
            // Backfill any signal that was missing when the row was first written.
            'cookie_hash'      => $row->cookie_hash ?? $cookie,
            'fingerprint_hash' => $row->fingerprint_hash ?? $fp,
        ]);
    }

    /** Has this visitor already consumed their one free use of $service? */
    public function hasUsed(Request $request, string $service): bool
    {
        [$ip, $fp, $cookie] = $this->identify($request);

        foreach ($this->matches($ip, $fp, $cookie) as $row) {
            if (isset(($row->services_used ?? [])[$service])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record that this visitor has now used $service (idempotent per service).
     *
     * Wrapped in a transaction with a row lock so concurrent records for the same
     * visitor (e.g. study + service fired together) serialize their read-modify-write
     * of the services_used JSON map instead of clobbering each other's keys. The
     * unique (ip_hash, fingerprint_hash) index guards against duplicate rows; if a
     * concurrent request wins the insert race we catch the violation and merge instead.
     */
    public function record(Request $request, string $service): void
    {
        [$ip, $fp, $cookie] = $this->identify($request);

        DB::transaction(function () use ($ip, $fp, $cookie, $service) {
            $row = $this->matchQuery($ip, $fp, $cookie)->lockForUpdate()->first();

            if ($row) {
                $this->mergeService($row, $service, $fp, $cookie);

                return;
            }

            try {
                GuestTracking::create([
                    'ip_hash'          => $ip ?? 'unknown',
                    'fingerprint_hash' => $fp,
                    'cookie_hash'      => $cookie,
                    'services_used'    => [$service => Carbon::now()->toIso8601String()],
                ]);
            } catch (QueryException $e) {
                // A concurrent request created the row first (unique ip_hash+fingerprint_hash).
                // Re-select it under lock and merge this service in.
                $row = $this->matchQuery($ip, $fp, $cookie)->lockForUpdate()->first();
                if ($row) {
                    $this->mergeService($row, $service, $fp, $cookie);

                    return;
                }
                throw $e;
            }
        });
    }
}
