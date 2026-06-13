<?php

namespace App\Http\Controllers;

use App\Jobs\RestartService;
use App\Jobs\RunPackageUpgrade;
use App\Jobs\RunUpdateCheck;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live system-monitor endpoints for the admin dashboard.
 *
 * GET  /admin/updates/status          — reads the cached JSON snapshot
 * POST /admin/updates/check           — queues a fresh check (sets checking=true)
 * POST /admin/updates/git-pull        — queues git pull + fresh check
 * POST /admin/updates/install         — queues pip upgrade for a whitelisted package
 * POST /admin/updates/restart-service — queues sudo systemctl restart for a whitelisted service
 *
 * All routes sit behind auth:sanctum + the `admin` middleware, so callers are
 * always authenticated administrators.
 */
class UpdateController extends Controller
{
    private const CACHE_FILE = '/tmp/aivc_update_status.json';

    private const ALLOWED_PACKAGES = [
        'edge-tts', 'anthropic', 'celery', 'redis', 'requests',
        'torch', 'transformers', 'httpx', 'fastapi', 'uvicorn', 'boto3', 'scipy',
    ];

    private const ALLOWED_SERVICES = [
        'aivc-workers', 'aivc-workers-music', 'aivc-bridge',
        'aivc-queue', 'aivc-scheduler', 'aivc-tedim-api', 'aivc-burmese-api',
    ];

    /** Return the cached snapshot, or a no-data sentinel if the cache is absent. */
    public function status(): JsonResponse
    {
        PermissionService::require(request()->user(), 'dashboard.view');

        if (!file_exists(self::CACHE_FILE)) {
            return response()->json(['status' => 'no_data', 'checking' => false]);
        }

        $data = json_decode(file_get_contents(self::CACHE_FILE), true);
        return response()->json($data ?? ['status' => 'no_data', 'checking' => false]);
    }

    /** Queue a fresh check without git pull. */
    public function check(): JsonResponse
    {
        PermissionService::require(request()->user(), 'dashboard.view');
        $this->markChecking();
        RunUpdateCheck::dispatch(false);
        return response()->json(['ok' => true, 'checking' => true]);
    }

    /** Queue a git pull followed by a fresh check. */
    public function gitPull(): JsonResponse
    {
        PermissionService::require(request()->user(), 'dashboard.view');
        $this->markChecking();
        RunUpdateCheck::dispatch(true);
        return response()->json(['ok' => true, 'checking' => true]);
    }

    /** Queue a pip upgrade for a single whitelisted package. */
    public function install(Request $request): JsonResponse
    {
        PermissionService::require(request()->user(), 'dashboard.view');

        $package = $request->string('package')->toString();
        if (!in_array($package, self::ALLOWED_PACKAGES, true)) {
            return response()->json(['error' => 'Package not on the allowed list.'], 422);
        }

        $this->markChecking();
        RunPackageUpgrade::dispatch($package);
        return response()->json(['ok' => true, 'message' => "Upgrade of {$package} queued."]);
    }

    /** Queue a sudo systemctl restart for a whitelisted service. */
    public function restartService(Request $request): JsonResponse
    {
        PermissionService::require(request()->user(), 'dashboard.view');

        $service = $request->string('service')->toString();
        if (!in_array($service, self::ALLOWED_SERVICES, true)) {
            return response()->json(['error' => 'Service not on the allowed list.'], 422);
        }

        RestartService::dispatch($service);
        return response()->json(['ok' => true, 'message' => "Restart of {$service} queued."]);
    }

    /** Write checking=true into the cache so the UI can show a spinner immediately. */
    private function markChecking(): void
    {
        $current = [];
        if (file_exists(self::CACHE_FILE)) {
            $current = json_decode(file_get_contents(self::CACHE_FILE), true) ?? [];
        }
        $current['checking'] = true;
        file_put_contents(self::CACHE_FILE, json_encode($current));
    }
}
