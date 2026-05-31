<?php

use App\Http\Controllers\Api\Platforms\FreshaController;
use Illuminate\Support\Facades\Route;

// Test-mode platform integration endpoints. Single-tenant cache, no auth.
// Promotion plan documented in App\Http\Controllers\Api\Platforms\FreshaController.

Route::prefix('platforms/fresha')
    ->middleware('throttle:public-site')
    ->group(function () {
        Route::post('/connect', [FreshaController::class, 'connect']);
        Route::get('/team', [FreshaController::class, 'team']);
        Route::get('/url', [FreshaController::class, 'show']);
        Route::delete('/', [FreshaController::class, 'forget']);
    });
