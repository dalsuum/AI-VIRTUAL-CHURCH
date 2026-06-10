<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\OfferingController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\TestimonyController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Public app configuration (intake options) — read before a worshipper has a session.
Route::get('/config', [ConfigController::class, 'show']);

// Public auth
Route::post('/guest',    [AuthController::class, 'guest']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

// Internal worker callbacks (shared-secret protected, no user auth)
Route::post('/internal/asset-ready', [WebhookController::class, 'assetReady']);
Route::post('/internal/music-track', [WebhookController::class, 'musicTrack']);

// Stripe offering webhook (Stripe-signature verified, no user auth)
Route::post('/webhooks/stripe', [OfferingController::class, 'webhook']);

// Authenticated user routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me/music-source', [AuthController::class, 'updateMusicSource']);

    Route::post('/service/start', [ServiceController::class, 'start']);
    Route::post('/service/{token}/intake', [ServiceController::class, 'intake']);
    Route::get('/service/{token}', [ServiceController::class, 'show']);

    // Offering segment — open a PaymentIntent; the browser confirms with Stripe.
    Route::post('/service/{token}/offering', [OfferingController::class, 'createIntent']);

    // Testimonies — read the approved wall, or share your own (held for moderation).
    Route::get('/testimonies', [TestimonyController::class, 'index']);
    Route::post('/testimonies', [TestimonyController::class, 'store']);

    // Admin console — every route additionally requires an is_admin account.
    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard']);

        Route::get('/services', [AdminController::class, 'services']);
        Route::post('/services/{service}/retry', [AdminController::class, 'retryService']);

        Route::get('/testimonies', [AdminController::class, 'testimonies']);
        Route::patch('/testimonies/{testimony}/approve', [AdminController::class, 'approveTestimony']);
        Route::delete('/testimonies/{testimony}', [AdminController::class, 'deleteTestimony']);

        Route::get('/users', [AdminController::class, 'users']);
        Route::patch('/users/{user}/admin', [AdminController::class, 'setAdmin']);

        Route::get('/donors', [AdminController::class, 'donors']);

        // Global service settings (e.g. narration voice mode).
        Route::get('/settings', [AdminController::class, 'settings']);
        Route::patch('/settings', [AdminController::class, 'updateSettings']);

        // CSV report export: donations | users | testimonies
        Route::get('/export/{type}', [AdminController::class, 'export']);
    });
});
