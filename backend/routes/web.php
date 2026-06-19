<?php

use App\Http\Controllers\ShareController;
use Illuminate\Support\Facades\Route;

// ===========================================================================
// Public sticker share pages — SELF-CONTAINED & REMOVABLE.
// Served from the MAIN domain (aivirtual.church/s/<id>) via an nginx mapping so
// shared links show a clean Open-Graph preview on the public domain, not api.*.
// Delete this block + ShareController + the /s//si nginx locations to remove.
// ===========================================================================
Route::get('/s/{jobId}/{n?}', [ShareController::class, 'page'])
    ->whereNumber('n')->middleware('throttle:120,1');
Route::get('/si/{jobId}/{n}', [ShareController::class, 'image'])
    ->whereNumber('n')->middleware('throttle:240,1');

// Father's Day MV share pages — SELF-CONTAINED & REMOVABLE (needs /v//vi//vp
// nginx locations on the main domain). Delete with the rest of the feature.
Route::get('/v/{jobId}',  [ShareController::class, 'videoPage'])->middleware('throttle:120,1');
Route::get('/vi/{jobId}', [ShareController::class, 'video'])->middleware('throttle:240,1');
Route::get('/vp/{jobId}', [ShareController::class, 'videoPoster'])->middleware('throttle:240,1');
