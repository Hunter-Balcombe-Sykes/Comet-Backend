<?php

use App\Http\Controllers\Api\PublicSite\QrCodeController;
use Illuminate\Support\Facades\Route;

Route::get('/p/{professionalId}.svg', [QrCodeController::class, 'svg'])
    ->where('professionalId', '[0-9a-fA-F-]{36}')
    ->middleware('throttle:public-site');
