<?php

// tests/Unit/Analytics/AnalyticsModelFillableTest.php
//
// FOUND-4 guard: the v2 migration (20260610000000) added destination/geo/device
// columns to analytics.link_clicks and analytics.site_visits, but $fillable was
// never updated. The writer uses insertOrIgnore() today (bypasses $fillable), so
// nothing is broken yet — this guards a future ::create()/mass-assignment caller
// from silently dropping these columns to null.

use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SiteVisit;

it('mass-assigns all v2 destination and geo/device columns on LinkClick', function () {
    $attributes = [
        'url' => 'https://x.test',
        'platform' => 'instagram',
        'product_id' => 'p1',
        'product_title' => 't',
        'section_key' => 'shop',
        'label' => 'l',
        'country_code' => 'AU',
        'region_code' => 'NSW',
        'device_type' => 'mobile',
    ];

    $model = (new LinkClick)->fill($attributes);

    foreach ($attributes as $key => $value) {
        expect($model->{$key})->toBe($value);
    }
});

it('keeps tenancy/relationship FKs guarded on LinkClick', function () {
    $model = new LinkClick;

    expect($model->isFillable('user_id'))->toBeFalse()
        ->and($model->isFillable('site_id'))->toBeFalse()
        ->and($model->isFillable('link_block_id'))->toBeFalse();
});

it('mass-assigns region_code on SiteVisit', function () {
    $model = (new SiteVisit)->fill(['region_code' => 'NSW']);

    expect($model->region_code)->toBe('NSW')
        ->and((new SiteVisit)->getFillable())->toContain('region_code');
});
