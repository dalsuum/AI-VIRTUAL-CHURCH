<?php

use App\Http\Controllers\AdController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BibleController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\OfferingController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SongController;
use App\Http\Controllers\SpecialSundayController;
use App\Http\Controllers\TestimonyController;
use App\Http\Controllers\UpdateController;
use App\Http\Controllers\VocabularyController;
use App\Http\Controllers\VoiceboxController;
use App\Http\Controllers\VoiceStudioController;
use App\Http\Controllers\VoiceTrainingController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Public app configuration (intake options) — read before a worshipper has a session.
Route::get('/config', [ConfigController::class, 'show']);

// Public worship song library — feeds the front song panel (my/td).
Route::get('/songs', [SongController::class, 'index']);

// Public Zolai ↔ Burmese ↔ English vocabulary reference (#vocabulary page).
Route::get('/vocabulary', [VocabularyController::class, 'index']);

// Online Bible reader — public, read-only (en BSB / my Judson 1835 / td Tedim 1932).
Route::get('/bible/config', [BibleController::class, 'config'])->middleware('throttle:120,1');
Route::get('/bible/books', [BibleController::class, 'books'])->middleware('throttle:120,1');
Route::get('/bible/chapter', [BibleController::class, 'chapter'])->middleware('throttle:120,1');
// Chapter narration (text-to-speech) — heavier, so throttled tighter.
Route::get('/bible/audio', [BibleController::class, 'audio'])->middleware('throttle:30,1');
// AI background-music loop for a chapter + reader time-of-day (cached/generated).
Route::get('/bible/bg-music', [BibleController::class, 'bgMusic'])->middleware('throttle:60,1');

// Public special-Sunday highlight — the active observance (if any) for the
// intake/home card, localized to ?language=en|my|td.
Route::get('/special-sunday/current', [SpecialSundayController::class, 'current'])
    ->middleware('throttle:60,1');

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

// ===========================================================================
// Father's Day (Special Day) MV — SELF-CONTAINED & REMOVABLE.
// Public visitors upload father photo(s) + pick an effect; we render a vertical
// MP4 to the admin-provided song/lyrics. Delete this block + FathersDayController
// + RenderFathersDayJob + storage/app/fathersday/ to remove the feature.
// ===========================================================================
Route::get('/fathers-day/config', [\App\Http\Controllers\FathersDayController::class, 'publicConfig']);
Route::post('/fathers-day/render', [\App\Http\Controllers\FathersDayController::class, 'render'])
    ->middleware('throttle:10,1');
Route::get('/fathers-day/job/{jobId}', [\App\Http\Controllers\FathersDayController::class, 'status'])
    ->middleware('throttle:120,1');
Route::get('/fathers-day/download/{jobId}', [\App\Http\Controllers\FathersDayController::class, 'download']);
Route::get('/fathers-day/song/{songId}/audio', [\App\Http\Controllers\FathersDayController::class, 'publicSong'])
    ->middleware('throttle:60,1');

// ===========================================================================
// Live Sticker maker — SELF-CONTAINED & REMOVABLE.
// Public visitors upload any photo; we auto face-crop and composite 5 random
// PNG stickers from Father's Day lyrics or typed text. Delete this block +
// StickerController + RenderStickerJob + workers/tools/sticker_render.py +
// storage/app/stickers/ + frontend LiveSticker.vue to remove the feature.
// ===========================================================================
Route::get('/stickers/config', [\App\Http\Controllers\StickerController::class, 'publicConfig']);
Route::post('/stickers/detect', [\App\Http\Controllers\StickerController::class, 'detect'])
    ->middleware('throttle:20,1');
Route::post('/stickers/render', [\App\Http\Controllers\StickerController::class, 'render'])
    ->middleware('throttle:20,1');
Route::get('/stickers/job/{jobId}', [\App\Http\Controllers\StickerController::class, 'status'])
    ->middleware('throttle:120,1');
Route::get('/stickers/image/{jobId}/{n}', [\App\Http\Controllers\StickerController::class, 'image'])
    ->whereNumber('n');

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
        // Bible AI background-music matrix status (read — staff may view).
        Route::get('/bible/bg-music/status', [AdminController::class, 'bibleBgMusicStatus']);
        Route::get('/music-tracks', [AdminController::class, 'musicTracks']);
        Route::get('/permissions',  [AdminController::class, 'getPermissions']);
        Route::get('/grammar-review',  [AdminController::class, 'grammarReview']);
        Route::post('/grammar-review', [AdminController::class, 'grammarReviewSave']);

        // Song library CRUD — each method enforces the `lyrics.manage` permission.
        Route::post('/songs/import',   [SongController::class, 'import']);
        Route::get('/songs/{song}',    [SongController::class, 'show']);
        Route::post('/songs',          [SongController::class, 'store']);
        Route::patch('/songs/{song}',  [SongController::class, 'update']);
        Route::delete('/songs/{song}', [SongController::class, 'destroy']);

        // Vocabulary CRUD — each method enforces the `vocabulary.manage` permission.
        Route::post('/vocabulary',                [VocabularyController::class, 'store']);
        Route::patch('/vocabulary/{vocabulary}',  [VocabularyController::class, 'update']);
        Route::delete('/vocabulary/{vocabulary}', [VocabularyController::class, 'destroy']);

        // Ads — reads available to staff with ads.view permission.
        Route::get('/ads',           [AdController::class, 'index']);
        Route::get('/ads/{ad}',      [AdController::class, 'show']);
        Route::get('/ads-analytics', [AdController::class, 'analytics']);

        // Special Sundays — monitor + catalog read (special_sundays.view).
        Route::get('/special-sundays', [AdminController::class, 'specialSundays']);
        // Preview what would play for a language + mood (read-only, no dispatch).
        Route::get('/special-sundays/{specialSunday}/preview', [AdminController::class, 'previewSpecialSunday']);

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

        // Bulk Deletes
        Route::post('/services/bulk-delete', [AdminController::class, 'bulkDeleteServices']);
        Route::post('/users/bulk-delete',    [AdminController::class, 'bulkDeleteUsers']);

        // Settings write
        Route::patch('/settings', [AdminController::class, 'updateSettings']);
        // Queue AI background-music generation for the whole theme x tod matrix.
        Route::post('/bible/bg-music/pregenerate', [AdminController::class, 'bibleBgMusicPregenerate']);

        // Content filter — categorized YouTube blocklist (CRUD + import/export).
        Route::get('/content-filter',                      [\App\Http\Controllers\ContentFilterController::class, 'index']);
        Route::put('/content-filter',                      [\App\Http\Controllers\ContentFilterController::class, 'replace']);
        Route::get('/content-filter/export.json',          [\App\Http\Controllers\ContentFilterController::class, 'exportJson']);
        Route::get('/content-filter/export.csv',           [\App\Http\Controllers\ContentFilterController::class, 'exportCsv']);
        Route::post('/content-filter/categories',          [\App\Http\Controllers\ContentFilterController::class, 'addCategory']);
        Route::patch('/content-filter/categories/{id}',    [\App\Http\Controllers\ContentFilterController::class, 'updateCategory']);
        Route::delete('/content-filter/categories/{id}',   [\App\Http\Controllers\ContentFilterController::class, 'deleteCategory']);
        Route::post('/content-filter/categories/{id}/keywords',   [\App\Http\Controllers\ContentFilterController::class, 'addKeyword']);
        Route::patch('/content-filter/categories/{id}/keywords',  [\App\Http\Controllers\ContentFilterController::class, 'updateKeyword']);
        Route::delete('/content-filter/categories/{id}/keywords', [\App\Http\Controllers\ContentFilterController::class, 'deleteKeyword']);

        // CSV export
        Route::get('/export/{type}', [AdminController::class, 'export']);

        // Music pool writes
        Route::post('/music-tracks',                   [AdminController::class, 'createMusicTrack']);
        Route::patch('/music-tracks/{musicTrack}',     [AdminController::class, 'updateMusicTrack']);
        Route::delete('/music-tracks/{musicTrack}',    [AdminController::class, 'deleteMusicTrack']);

        // Permissions write
        Route::patch('/permissions', [AdminController::class, 'updatePermissions']);

        // Special Sundays — manual add / edit / enable-disable / delete
        // (special_sundays.manage). Auto-seeded rows are editable here.
        Route::post('/special-sundays',                    [AdminController::class, 'createSpecialSunday']);
        Route::patch('/special-sundays/{specialSunday}',   [AdminController::class, 'updateSpecialSunday']);
        Route::delete('/special-sundays/{specialSunday}',  [AdminController::class, 'deleteSpecialSunday']);

        // Curated sermon/song libraries attached to a special Sunday (manual mode).
        Route::post('/special-sundays/{specialSunday}/sermons', [AdminController::class, 'createSpecialSermon']);
        Route::patch('/special-sermons/{specialSermon}',        [AdminController::class, 'updateSpecialSermon']);
        Route::delete('/special-sermons/{specialSermon}',       [AdminController::class, 'deleteSpecialSermon']);

        Route::post('/special-sundays/{specialSunday}/songs',   [AdminController::class, 'createSpecialSong']);
        Route::patch('/special-songs/{specialSong}',            [AdminController::class, 'updateSpecialSong']);
        Route::delete('/special-songs/{specialSong}',           [AdminController::class, 'deleteSpecialSong']);

        // Ads — admin writes (create, update, delete, slide management, image upload).
        Route::post('/ads',                               [AdController::class, 'store']);
        Route::patch('/ads/{ad}',                         [AdController::class, 'update']);
        Route::delete('/ads/{ad}',                        [AdController::class, 'destroy']);
        Route::post('/ads/{ad}/slides',                   [AdController::class, 'storeSlide']);
        Route::patch('/ads/{ad}/slides/{slide}',          [AdController::class, 'updateSlide']);
        Route::delete('/ads/{ad}/slides/{slide}',         [AdController::class, 'destroySlide']);
        Route::post('/ads/{ad}/slides/{slide}/image',     [AdController::class, 'uploadSlideImage']);
        Route::post('/ads/{ad}/reorder',                  [AdController::class, 'reorderSlides']);

        // Father's Day (Special Day) MV — admin config + song upload (removable feature).
        Route::get('/fathers-day',                      [\App\Http\Controllers\FathersDayController::class, 'adminShow']);
        Route::post('/fathers-day',                     [\App\Http\Controllers\FathersDayController::class, 'adminSave']);
        Route::post('/fathers-day/songs',               [\App\Http\Controllers\FathersDayController::class, 'createSong']);
        Route::patch('/fathers-day/songs/{songId}',     [\App\Http\Controllers\FathersDayController::class, 'updateSong']);
        Route::delete('/fathers-day/songs/{songId}',    [\App\Http\Controllers\FathersDayController::class, 'deleteSong']);
        Route::get('/fathers-day/songs/{songId}/audio', [\App\Http\Controllers\FathersDayController::class, 'adminSong']);
        Route::post('/fathers-day/reset-usage',         [\App\Http\Controllers\FathersDayController::class, 'resetUsage']);
        Route::post('/fathers-day/brand-tag',           [\App\Http\Controllers\FathersDayController::class, 'uploadBrandTag']);
        Route::delete('/fathers-day/brand-tag',         [\App\Http\Controllers\FathersDayController::class, 'deleteBrandTag']);

        // Live Sticker — admin enable/disable + page copy (removable feature).
        Route::get('/stickers',             [\App\Http\Controllers\StickerController::class, 'adminShow']);
        Route::post('/stickers',            [\App\Http\Controllers\StickerController::class, 'adminSave']);
        Route::post('/stickers/reset-usage',[\App\Http\Controllers\StickerController::class, 'resetUsage']);

        // System actions — read-only refresh is enabled; git pull / package install stay disabled (destructive).
        Route::post('/updates/check',           [UpdateController::class, 'check']);
        // Route::post('/updates/git-pull',        [UpdateController::class, 'gitPull']);
        // Route::post('/updates/install',         [UpdateController::class, 'install']);
        Route::post('/updates/restart-service', [UpdateController::class, 'restartService']);
    });
});
