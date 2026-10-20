<?php

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

use App\Http\Controllers\Api\Admin\OrchestratorApiController;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\ApiInformController;
use App\Http\Controllers\Api\ApiV2Controller;
use App\Services\ReleaseExtraService;

Route::prefix('v1')->group(function () {
    Route::match(['post', 'get'], 'api', [ApiController::class, 'api']);
});

Route::prefix('v2')->group(function () {
    Route::get('capabilities', [ApiV2Controller::class, 'capabilities']);
});

Route::prefix('v2')->middleware('apiRateLimit')->group(function () {
    Route::get('movies', [ApiV2Controller::class, 'movie']);
    Route::get('audio', [ApiV2Controller::class, 'audio']);
    Route::get('books', [ApiV2Controller::class, 'books']);
    Route::get('anime', [ApiV2Controller::class, 'anime']);
    Route::get('search', [ApiV2Controller::class, 'apiSearch']);
    Route::get('tv', [ApiV2Controller::class, 'tv']);
    Route::get('getnzb', [ApiV2Controller::class, 'getNzb']);
    Route::get('details', [ApiV2Controller::class, 'details']);
});

// nntmux-control webui surface. Read endpoint is identity-checked only
// (admin.apiUser); the control endpoint additionally requires the
// separate, more privileged orchestrator.controlToken secret. See
// docs/plans/2026-10-20-nntmux-control-webui.md for the one-writer
// invariant this must preserve: `mode()` may only call the
// nntmux:orchestrator-mode Artisan command, never write orchestrator
// state directly.
Route::prefix('v2/admin/orchestrator')->middleware(['apiRateLimit', 'admin.apiUser'])->group(function () {
    Route::get('status', [OrchestratorApiController::class, 'status'])->name('api.v2.admin.orchestrator.status');

    Route::post('mode', [OrchestratorApiController::class, 'mode'])
        ->middleware('orchestrator.controlToken')
        ->name('api.v2.admin.orchestrator.mode');
});

Route::prefix('inform')->group(function () {
    Route::get('release', [ApiInformController::class, 'release']);
});

// Mediainfo endpoint (no auth required for internal use)
Route::get('release/{id}/mediainfo', function ($id) {
    $releaseExtra = app(ReleaseExtraService::class);

    $video = $releaseExtra->getVideo($id);
    $audio = $releaseExtra->getAudio($id);
    $subs = $releaseExtra->getSubs($id);

    return response()->json([
        'video' => $video ?: null,
        'audio' => $audio ?: null,
        'subs' => $subs ? $subs->subs : null, // @phpstan-ignore property.notFound
    ]);
});

Route::fallback(function () {
    return response()->json(['message' => 'Not Found!'], 404);
});
