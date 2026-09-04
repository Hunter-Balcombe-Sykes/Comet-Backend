<?php

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// site_id is the tenancy FK (SEC-1) — must never be settable via mass-assignment
// from request input. Trusted write paths set it through ->site()->associate($site)
// instead, which bypasses $fillable entirely.
it('does not allow site_id to be mass-assigned on SiteMedia', function () {
    $media = new SiteMedia(['site_id' => 'attacker-site-id', 'usage' => 'content']);

    expect($media->site_id)->toBeNull();
});

it('sets site_id via the sanctioned site()->associate() write path', function () {
    $site = new Site(['subdomain' => 'example']);
    $site->id = '11111111-1111-1111-1111-111111111111';

    $media = (new SiteMedia)->site()->associate($site);

    expect($media->site_id)->toBe($site->id);
});
