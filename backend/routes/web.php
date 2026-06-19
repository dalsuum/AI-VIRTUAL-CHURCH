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
