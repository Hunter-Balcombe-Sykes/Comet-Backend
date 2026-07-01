<?php

// FOUND-16: unit test verifying SitepageDataResolverService::buildServicesData()
// reads booking_mode / manual_booking_url from the promoted columns (column-first),
// falling back to settings only when the column is absent.

use App\Models\Core\Site\Site;
use App\Services\PublicSite\SitepageDataResolverService;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    // buildServicesData queries site.services; stub an empty result so the test
    // is purely column-vs-settings focused without a full DB fixture.
    setupServicesTable();
});

it('reads booking_mode from the column not settings', function () {
    $site = new Site(['settings' => []]); // JSONB empty
    $site->booking_mode = 'none';
    $site->manual_booking_url = 'https://example.com/x';

    $data = app(SitepageDataResolverService::class)
        ->buildServicesData($site, (string) Str::uuid());

    expect($data['booking_mode'])->toBe('none')
        ->and($data['manual_booking_url'])->toBe('https://example.com/x');
});

it('falls back to settings when the column is null', function () {
    $site = new Site(['settings' => ['booking_mode' => 'manual', 'manual_booking_url' => 'https://fallback.com']]);
    $site->booking_mode = null;
    $site->manual_booking_url = null;

    $data = app(SitepageDataResolverService::class)
        ->buildServicesData($site, (string) Str::uuid());

    expect($data['booking_mode'])->toBe('manual')
        ->and($data['manual_booking_url'])->toBe('https://fallback.com');
});

it('column wins over a stale residual JSONB value during dual-write', function () {
    $site = new Site(['settings' => ['booking_mode' => 'manual']]); // old JSONB
    $site->booking_mode = 'none'; // column is authoritative

    $data = app(SitepageDataResolverService::class)
        ->buildServicesData($site, (string) Str::uuid());

    expect($data['booking_mode'])->toBe('none');
});
