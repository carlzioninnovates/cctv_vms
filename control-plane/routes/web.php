<?php

use App\Http\Controllers\AgentReleaseController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/sites'));

// Site agent installer + release files (public; integrity via SHA-256 embedded in the installer)
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/install-agent.sh', [AgentReleaseController::class, 'installer'])->name('agent.installer');
    Route::get('/downloads/agent/{file}', [AgentReleaseController::class, 'download'])->where('file', '[A-Za-z0-9._-]+');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/sites', [DashboardController::class, 'sites'])->name('sites');
    Route::get('/sites/{site}', [DashboardController::class, 'site'])->name('site');
    Route::get('/sites/{site}/live', [DashboardController::class, 'live'])->name('live');
    Route::get('/nvrs/{nvr}', [DashboardController::class, 'nvr'])->name('nvr');
    Route::get('/help', [DashboardController::class, 'help'])->name('help');
});
