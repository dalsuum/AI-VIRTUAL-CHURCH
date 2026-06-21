<?php

namespace App\Services;

use App\Models\GuestTracking;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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

    /** Rows that plausibly belong to the same visitor. */
    private function matches(?string $ip, ?string $fp, ?string $cookie)
    {
        return GuestTracking::query()
            ->when($cookie, fn ($q) => $q->orWhere('cookie_hash', $cookie))
            ->when($ip && $fp, fn ($q) => $q->orWhere(fn ($w) => $w->where('ip_hash', $ip)->where('fingerprint_hash', $fp)))
            ->get();
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

    /** Record that this visitor has now used $service (idempotent per service). */
    public function record(Request $request, string $service): void
    {
        [$ip, $fp, $cookie] = $this->identify($request);

        $row = $this->matches($ip, $fp, $cookie)->first();

        $used = $row->services_used ?? [];
        $used[$service] = $used[$service] ?? Carbon::now()->toIso8601String();

        if ($row) {
            $row->update([
                'services_used'    => $used,
                // Backfill any signal that was missing when the row was first written.
                'cookie_hash'      => $row->cookie_hash ?? $cookie,
                'fingerprint_hash' => $row->fingerprint_hash ?? $fp,
            ]);

            return;
        }

        GuestTracking::create([
            'ip_hash'          => $ip ?? 'unknown',
            'fingerprint_hash' => $fp,
            'cookie_hash'      => $cookie,
            'services_used'    => $used,
        ]);
    }
}
