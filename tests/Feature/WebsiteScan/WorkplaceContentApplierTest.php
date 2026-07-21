<?php

use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\WebsiteScan\WorkplaceContentApplier;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupWorkplacesTable();
});

it('fills description only when blank, stamping field_sources provenance', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $workplace = Workplace::create(['site_id' => (string) $site->id]);

    app(WorkplaceContentApplier::class)->applyDescription($workplace, 'Wood-fired pizza since 1985.');

    $fresh = Workplace::where('site_id', (string) $site->id)->first();
    expect($fresh->description)->toBe('Wood-fired pizza since 1985.');
    expect($fresh->field_sources['description']['source'])->toBe('website-scan');
});

it('never overwrites an existing description', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $workplace = Workplace::create(['site_id' => (string) $site->id, 'description' => 'Owner-written description.']);

    app(WorkplaceContentApplier::class)->applyDescription($workplace, 'Scraped description.');

    expect(Workplace::where('site_id', (string) $site->id)->first()->description)->toBe('Owner-written description.');
});

it('does nothing when given a null or blank text', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $workplace = Workplace::create(['site_id' => (string) $site->id]);

    app(WorkplaceContentApplier::class)->applyDescription($workplace, null);
    app(WorkplaceContentApplier::class)->applyDescription($workplace, '   ');

    expect(Workplace::where('site_id', (string) $site->id)->first()->description)->toBeNull();
});

it('trims the text before saving', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $workplace = Workplace::create(['site_id' => (string) $site->id]);

    app(WorkplaceContentApplier::class)->applyDescription($workplace, '  Padded.  ');

    expect(Workplace::where('site_id', (string) $site->id)->first()->description)->toBe('Padded.');
});
