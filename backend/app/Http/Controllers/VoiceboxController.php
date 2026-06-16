<?php

namespace App\Http\Controllers;

use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

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
        PermissionService::require(request()->user(), 'system.view');

        try {
            $response = Http::timeout(self::TIMEOUT)->get(self::BASE . '/health');
            if ($response->successful()) {
                return response()->json(['status' => 'ok'] + ($response->json() ?? []));
            }
            return response()->json(['status' => 'unreachable']);
        } catch (\Throwable) {
            return response()->json(['status' => 'unreachable']);
        }
    }

    /** GET /admin/voicebox/profiles — proxies Voicebox GET /profiles */
    public function profiles(): JsonResponse
    {
        PermissionService::require(request()->user(), 'system.view');

        try {
            $response = Http::timeout(self::TIMEOUT)->get(self::BASE . '/profiles');
            if (!$response->successful()) {
                return response()->json(['status' => 'unreachable', 'profiles' => []]);
            }

            $profiles = $response->json() ?? [];
            // Voicebox's /profiles response does not include sample_count in the
            // current Docker image, so enrich each profile from /profiles/{id}/samples.
            $slim = array_map(function ($p) {
                $id = $p['id'] ?? '';
                $samples = [];
                if ($id) {
                    try {
                        $sampleResp = Http::timeout(self::TIMEOUT)->get(self::BASE . "/profiles/{$id}/samples");
                        $samples = $sampleResp->successful() ? ($sampleResp->json() ?? []) : [];
                    } catch (\Throwable) {}
                }

                return [
                    'id'           => $id,
                    'name'         => $p['name'] ?? '',
                    'voice_type'   => $p['voice_type'] ?? 'clone',
                    'language'     => $p['language'] ?? '',
                    'sample_count' => is_array($samples) ? count($samples) : 0,
                ];
            }, is_array($profiles) ? $profiles : []);

            return response()->json(['status' => 'ok', 'profiles' => $slim]);
        } catch (\Throwable) {
            return response()->json(['status' => 'unreachable', 'profiles' => []]);
        }
    }

    /** GET /admin/voicebox/queue — proxies Voicebox GET /tasks/active */
    public function queue(): JsonResponse
    {
        PermissionService::require(request()->user(), 'system.view');

        try {
            $response = Http::timeout(self::TIMEOUT)->get(self::BASE . '/tasks/active');
            if ($response->successful()) {
                $data = $response->json() ?? [];
                return response()->json([
                    'status'      => 'ok',
                    'generations' => count($data['generations'] ?? []),
                    'downloads'   => count($data['downloads'] ?? []),
                ]);
            }
            return response()->json(['status' => 'unreachable']);
        } catch (\Throwable) {
            return response()->json(['status' => 'unreachable']);
        }
    }
}
