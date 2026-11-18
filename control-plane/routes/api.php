<?php

use App\Http\Controllers\Api\AgentEnrollmentController;
use App\Http\Controllers\Api\EdgeAgentController;
use App\Http\Controllers\Api\LiveController;
use App\Http\Controllers\Api\MediaAuthController;
use App\Http\Controllers\Api\NvrController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\SiteController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Agent enrollment: public, but codes are one-time, 24h, and attempts are throttled per IP.
Route::post('/agent/enroll', AgentEnrollmentController::class)->middleware('throttle:10,1');

// Called by MediaMTX (backend network only). The viewer token in the request is the credential.
Route::post('/internal/media/auth', MediaAuthController::class);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user()->only(['id', 'name', 'email', 'role', 'organization_id'])
        + ['organization' => $request->user()->organization?->only(['id', 'name', 'type'])]);

    // Clients and sites
    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::post('/organizations', [OrganizationController::class, 'store']);
    Route::get('/sites', [SiteController::class, 'index']);
    Route::post('/organizations/{organization}/sites', [SiteController::class, 'store']);
    Route::get('/sites/{site}', [SiteController::class, 'show']);

    // Onboarding
    Route::post('/sites/{site}/agents', [EdgeAgentController::class, 'store']);
    Route::get('/agents/{agent}', [EdgeAgentController::class, 'show']);
    Route::post('/agents/{agent}/enrollment-code', [EdgeAgentController::class, 'reissueCode']);
    Route::post('/agents/{agent}/discover', [EdgeAgentController::class, 'discover'])->middleware('throttle:6,1');

    Route::post('/sites/{site}/nvrs', [NvrController::class, 'store']);
    Route::get('/nvrs/{nvr}', [NvrController::class, 'show']);
    Route::post('/nvrs/{nvr}/retry', [NvrController::class, 'retry'])->middleware('throttle:10,1');
    // Live view
    Route::get('/sites/{site}/cameras', [LiveController::class, 'cameras']);
    Route::post('/channels/{channel}/stream', [LiveController::class, 'stream'])->middleware('throttle:60,1');
    Route::post('/channels/{channel}/ptz', [LiveController::class, 'ptz'])->middleware('throttle:240,1');

    Route::put('/nvrs/{nvr}/credentials', [NvrController::class, 'updateCredentials'])->middleware('throttle:10,1');
});
