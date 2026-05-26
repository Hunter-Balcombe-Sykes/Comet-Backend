<?php

use App\Exceptions\Gdpr\DataExportInProgressException;
use App\Exceptions\Streaming\KickRateLimitException;
use Illuminate\Support\Facades\Route;

it('renders KickRateLimitException as 429 with Retry-After header', function () {
    Route::get('api/__test/kick-rate-limit', function () {
        throw new KickRateLimitException(retryAfter: 30);
    });

    $response = $this->getJson('api/__test/kick-rate-limit');

    $response->assertStatus(429);
    $response->assertHeader('Retry-After', '30');
    $response->assertJson(['message' => 'Kick API rate limit exceeded.']);
});

it('renders KickRateLimitException as 429 without Retry-After when retryAfter is null', function () {
    Route::get('api/__test/kick-rate-limit-no-retry', function () {
        throw new KickRateLimitException();
    });

    $response = $this->getJson('api/__test/kick-rate-limit-no-retry');

    $response->assertStatus(429);
    $response->assertHeaderMissing('Retry-After');
});

it('renders DataExportInProgressException as 409', function () {
    Route::get('api/__test/data-export', function () {
        throw new DataExportInProgressException(existingExportId: 'abc-123');
    });

    $response = $this->getJson('api/__test/data-export');

    $response->assertStatus(409);
    $response->assertJson(['message' => 'A data export is already in progress for this professional.']);
});
