<?php

use App\Http\Controllers\Api\PublicSite\QrCodeController;
use App\Http\Controllers\Dev\MailPreviewController;
use Illuminate\Support\Facades\Route;

Route::get('/p/{professionalId}.svg', [QrCodeController::class, 'svg'])
    ->where('professionalId', '[0-9a-fA-F-]{36}')
    ->middleware('throttle:public-site');

// Local-only mail template gallery — never registered outside the local env.
if (app()->environment('local')) {
    Route::get('/dev/emails', [MailPreviewController::class, 'index']);
    Route::get('/dev/emails/{key}', [MailPreviewController::class, 'show']);
}
