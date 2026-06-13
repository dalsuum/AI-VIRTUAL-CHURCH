<?php

namespace App\Http\Controllers;

use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;

/**
 * Proxy endpoints for the local Voicebox TTS container (http://127.0.0.1:17493).
 * Used by the admin dashboard to monitor health, list voice profiles, and check
 * the generation queue — all without exposing Voicebox directly to the browser.
 *
 * Routes sit behind auth:sanctum + dashboard.view so only admins can poll these.
 * On connection failure (Voicebox down / not yet started), every method returns
 * {"status":"unreachable"} rather than a 5xx, so the dashboard can show a clean
 * "offline" state instead of an error.
 */
class VoiceboxController extends Controller
{
    private const BASE    = 'http://127.0.0.1:17493';
    private const TIMEOUT = 3; // fast fail — Voicebox is local

    /** GET /admin/voicebox/health — proxies Voicebox GET /health */
    public function health(): JsonResponse
    {
        PermissionService::require(request()->user(), 'dashboard.view');

        try {
            $ch = $this->curl(self::BASE . '/health');
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($body === false || $code !== 200) {
                return response()->json(['status' => 'unreachable']);
            }

            $data = json_decode($body, true) ?? [];
            return response()->json(['status' => 'ok'] + $data);
        } catch (\Throwable) {
            return response()->json(['status' => 'unreachable']);
        }
    }

    /** GET /admin/voicebox/profiles — proxies Voicebox GET /profiles */
    public function profiles(): JsonResponse
    {
        PermissionService::require(request()->user(), 'dashboard.view');

        try {
            $ch = $this->curl(self::BASE . '/profiles');
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($body === false || $code !== 200) {
                return response()->json(['status' => 'unreachable', 'profiles' => []]);
            }

            $profiles = json_decode($body, true) ?? [];
            // Trim to only the fields the dashboard needs.
            $slim = array_map(fn($p) => [
                'id'           => $p['id'] ?? '',
                'name'         => $p['name'] ?? '',
                'voice_type'   => $p['voice_type'] ?? '',
                'language'     => $p['language'] ?? '',
                'sample_count' => $p['sample_count'] ?? 0,
            ], is_array($profiles) ? $profiles : []);

            return response()->json(['status' => 'ok', 'profiles' => $slim]);
        } catch (\Throwable) {
            return response()->json(['status' => 'unreachable', 'profiles' => []]);
        }
    }

    /** GET /admin/voicebox/queue — proxies Voicebox GET /tasks/active */
    public function queue(): JsonResponse
    {
        PermissionService::require(request()->user(), 'dashboard.view');

        try {
            $ch = $this->curl(self::BASE . '/tasks/active');
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($body === false || $code !== 200) {
                return response()->json(['status' => 'unreachable']);
            }

            $data = json_decode($body, true) ?? [];
            return response()->json([
                'status'      => 'ok',
                'generations' => count($data['generations'] ?? []),
                'downloads'   => count($data['downloads'] ?? []),
            ]);
        } catch (\Throwable) {
            return response()->json(['status' => 'unreachable']);
        }
    }

    /** Build a cURL handle for a GET request with a short timeout. */
    private function curl(string $url): \CurlHandle
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPGET        => true,
        ]);
        return $ch;
    }
}
