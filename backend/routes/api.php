<?php

use App\Http\Controllers\AdController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\OfferingController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\TestimonyController;
use App\Http\Controllers\UpdateController;
use App\Http\Controllers\VoiceboxController;
use App\Http\Controllers\VoiceStudioController;
use App\Http\Controllers\VoiceTrainingController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Public app configuration (intake options) — read before a worshipper has a session.
Route::get('/config', [ConfigController::class, 'show']);

// Public auth — rate-limited per IP to slow credential stuffing and account spam.
Route::middleware('throttle:auth')->group(function () {
    Route::post('/guest',          [AuthController::class, 'guest']);
    Route::post('/register',       [AuthController::class, 'register']);
    Route::post('/login',          [AuthController::class, 'login']);
    Route::post('/forgot-password',[AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// Email-link session resume — public, session token acts as the credential.
// Throttled to limit repeated token exchange from a leaked link.
Route::get('/service/{token}/resume', [ServiceController::class, 'resume'])
    ->middleware('throttle:5,1');

// Internal worker callbacks (shared-secret protected, no user auth)
Route::post('/internal/asset-ready', [WebhookController::class, 'assetReady']);
Route::post('/internal/music-track', [WebhookController::class, 'musicTrack']);

// Stripe offering webhook (Stripe-signature verified, no user auth)
Route::post('/webhooks/stripe', [OfferingController::class, 'webhook']);

// Public ad endpoints — no auth required; track is throttled to prevent abuse.
Route::get('/ads/active', [AdController::class, 'activeForService']);
Route::post('/ads/track', [AdController::class, 'track'])->middleware('throttle:60,1');

// Authenticated user routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me/name',             [AuthController::class, 'updateName']);
    Route::patch('/me/email',            [AuthController::class, 'updateEmail']);
    Route::patch('/me/music-source',     [AuthController::class, 'updateMusicSource']);
    Route::patch('/me/presenter-gender', [AuthController::class, 'updatePresenterGender']);
    Route::post('/me/change-password',   [AuthController::class, 'changePassword']);

    Route::get('/me/services', [ServiceController::class, 'myServices']);
    Route::post('/service/start', [ServiceController::class, 'start']);
    // Intake triggers the full AI pipeline — throttled tightly per user/IP.
    Route::post('/service/{token}/intake', [ServiceController::class, 'intake'])
        ->middleware('throttle:intake');
    Route::get('/service/{token}', [ServiceController::class, 'show']);

    // Offering segment — open a PaymentIntent; the browser confirms with Stripe.
    Route::post('/service/{token}/offering', [OfferingController::class, 'createIntent']);

    // Testimonies — read the approved wall, or share your own (held for moderation).
    Route::get('/testimonies', [TestimonyController::class, 'index']);
    Route::post('/testimonies', [TestimonyController::class, 'store'])
        ->middleware('throttle:testimony');

    // Voice Studio — any authenticated user can record their own training data.
    Route::prefix('voice-studio')->group(function () {
        Route::get('/status',                 [VoiceStudioController::class, 'status']);
        Route::get('/script/{lang}',           [VoiceStudioController::class, 'script']);
        Route::get('/progress/{lang}',         [VoiceStudioController::class, 'progress']);
        Route::post('/recording',              [VoiceStudioController::class, 'store']);
        Route::post('/transcribe',             [VoiceStudioController::class, 'transcribe']);
        Route::get('/export/{lang}',           [VoiceStudioController::class, 'export']);
        Route::delete('/recording/{lang}/{id}',[VoiceStudioController::class, 'destroy']);
    });

    // Staff console — accessible to admin, moderator, and presenter roles.
    // Each controller method enforces its own fine-grained permission check via
    // PermissionService::require(), so entry here doesn't grant blanket access.
    Route::prefix('admin')->middleware('staff')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard']);

        Route::get('/services', [AdminController::class, 'services']);
        Route::post('/services/{service}/retry', [AdminController::class, 'retryService']);
        Route::delete('/services/{service}', [AdminController::class, 'deleteService']);

        Route::get('/testimonies', [AdminController::class, 'testimonies']);
        Route::patch('/testimonies/{testimony}/approve', [AdminController::class, 'approveTestimony']);
        Route::delete('/testimonies/{testimony}', [AdminController::class, 'deleteTestimony']);

        Route::get('/donors', [AdminController::class, 'donors']);
        Route::get('/prayer-requests', [AdminController::class, 'prayerRequests']);

        // Configurable-permission reads — each method checks its own permission.
        Route::get('/users',        [AdminController::class, 'users']);
        Route::get('/settings',     [AdminController::class, 'settings']);
        Route::get('/music-tracks', [AdminController::class, 'musicTracks']);
        Route::get('/permissions',  [AdminController::class, 'getPermissions']);
        Route::get('/grammar-review',  [AdminController::class, 'grammarReview']);
        Route::post('/grammar-review', [AdminController::class, 'grammarReviewSave']);

        // Ads — reads available to staff with ads.view permission.
        Route::get('/ads',           [AdController::class, 'index']);
        Route::get('/ads/{ad}',      [AdController::class, 'show']);
        Route::get('/ads-analytics', [AdController::class, 'analytics']);

        Route::get('/voice-training/status', [VoiceTrainingController::class, 'status']);
        Route::post('/voice-training/start', [VoiceTrainingController::class, 'start']);
        Route::get('/updates/status',        [UpdateController::class, 'status']);
        Route::get('/voicebox/health',       [VoiceboxController::class, 'health']);
        Route::get('/voicebox/profiles',     [VoiceboxController::class, 'profiles']);
        Route::get('/voicebox/queue',        [VoiceboxController::class, 'queue']);
    });

    // Admin-only routes — full admin role required for sensitive writes.
    Route::prefix('admin')->middleware('admin')->group(function () {
        // User mutations
        Route::post('/users',                          [AdminController::class, 'createUser']);
        Route::patch('/users/{user}/role',             [AdminController::class, 'assignRole']);
        Route::patch('/users/{user}/admin',            [AdminController::class, 'setAdmin']);
        Route::patch('/users/{user}/block',            [AdminController::class, 'blockUser']);
        Route::patch('/users/{user}/presenter-gender', [AdminController::class, 'updatePresenterGender']);
        Route::post('/users/{user}/force-reset',       [AdminController::class, 'forcePasswordReset']);
        Route::delete('/users/{user}',                 [AdminController::class, 'deleteUser']);

        // Settings write
        Route::patch('/settings', [AdminController::class, 'updateSettings']);

        // CSV export
        Route::get('/export/{type}', [AdminController::class, 'export']);

        // Music pool writes
        Route::post('/music-tracks',                   [AdminController::class, 'createMusicTrack']);
        Route::patch('/music-tracks/{musicTrack}',     [AdminController::class, 'updateMusicTrack']);
        Route::delete('/music-tracks/{musicTrack}',    [AdminController::class, 'deleteMusicTrack']);

        // Permissions write
        Route::patch('/permissions', [AdminController::class, 'updatePermissions']);

        // Ads — admin writes (create, update, delete, slide management, image upload).
        Route::post('/ads',                               [AdController::class, 'store']);
        Route::patch('/ads/{ad}',                         [AdController::class, 'update']);
        Route::delete('/ads/{ad}',                        [AdController::class, 'destroy']);
        Route::post('/ads/{ad}/slides',                   [AdController::class, 'storeSlide']);
        Route::patch('/ads/{ad}/slides/{slide}',          [AdController::class, 'updateSlide']);
        Route::delete('/ads/{ad}/slides/{slide}',         [AdController::class, 'destroySlide']);
        Route::post('/ads/{ad}/slides/{slide}/image',     [AdController::class, 'uploadSlideImage']);
        Route::post('/ads/{ad}/reorder',                  [AdController::class, 'reorderSlides']);

        // System actions (destructive — git pull, package installs, service restarts)
        // Route::post('/updates/check',           [UpdateController::class, 'check']);
        // Route::post('/updates/git-pull',        [UpdateController::class, 'gitPull']);
        // Route::post('/updates/install',         [UpdateController::class, 'install']);
        // Route::post('/updates/restart-service', [UpdateController::class, 'restartService']);
    });
});
