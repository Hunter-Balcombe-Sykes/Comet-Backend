<?php

use App\Http\Controllers\Api\V5\ContentPoolController;
use App\Http\Controllers\Api\V5\ItemController;
use App\Http\Controllers\Api\V5\PlatformController;
use App\Http\Controllers\Api\V5\PlatformDefinitionController;
use App\Http\Controllers\Api\V5\RouterController;
use App\Http\Middleware\Site\EnforcePendingDeletionReadOnly;
use Illuminate\Support\Facades\Route;

Route::middleware(['user.api', EnforcePendingDeletionReadOnly::class, 'throttle:authenticated'])
    ->prefix('v5')
    ->group(function (): void {
        // Platform definitions (global catalog)
        Route::get('platform-definitions', [PlatformDefinitionController::class, 'index']);
        Route::get('platform-definitions/{id}', [PlatformDefinitionController::class, 'show']);

        // User platforms (connected platforms for the current user)
        Route::get('platforms', [PlatformController::class, 'index']);
        Route::post('platforms', [PlatformController::class, 'store']);
        Route::get('platforms/{id}', [PlatformController::class, 'show']);
        Route::patch('platforms/{id}', [PlatformController::class, 'update']);
        Route::delete('platforms/{id}', [PlatformController::class, 'destroy']);
        Route::post('platforms/{id}/refresh', [PlatformController::class, 'refresh']);

        // Content pools
        Route::get('pools', [ContentPoolController::class, 'index']);
        Route::get('pools/{id}', [ContentPoolController::class, 'show']);
        Route::get('pools/{id}/items', [ContentPoolController::class, 'items']);

        // Items
        Route::get('items', [ItemController::class, 'index']);
        Route::post('items', [ItemController::class, 'store']);
        Route::get('items/{id}', [ItemController::class, 'show']);
        Route::patch('items/{id}', [ItemController::class, 'update']);
        Route::delete('items/{id}', [ItemController::class, 'destroy']);
        Route::post('items/{id}/select', [ItemController::class, 'select']);
        Route::post('items/{id}/deselect', [ItemController::class, 'deselect']);

        // Item values
        Route::get('items/{id}/values', [ItemController::class, 'values']);
        Route::patch('items/{id}/values/{valueId}', [ItemController::class, 'updateValue']);

        // Router
        Route::post('router/determine', [RouterController::class, 'determine']);

        // Categories
        Route::get('categories', [ContentPoolController::class, 'categories']);
    });
