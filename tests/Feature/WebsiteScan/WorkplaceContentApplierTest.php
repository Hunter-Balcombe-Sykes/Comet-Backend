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

// ── applyProseDescription() — overwrite-unless-manual precedence ─────────────

it('fills a blank description with prose, stamping field_sources provenance', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $workplace = Workplace::create(['site_id' => (string) $site->id]);

    app(WorkplaceContentApplier::class)->applyProseDescription($workplace, 'Real authored prose about the business.');

    $fresh = Workplace::where('site_id', (string) $site->id)->first();
    expect($fresh->description)->toBe('Real authored prose about the business.');
    expect($fresh->field_sources['description']['source'])->toBe('website-scan');
});

it('overwrites a google-business-sourced description with prose', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $workplace = Workplace::create(['site_id' => (string) $site->id, 'description' => "Google's editorial summary."]);
    $workplace->forceFill(['field_sources' => ['description' => ['source' => 'google-business', 'at' => now()->toIso8601String()]]])->save();

    app(WorkplaceContentApplier::class)->applyProseDescription($workplace, 'The business in its own words.');

    $fresh = Workplace::where('site_id', (string) $site->id)->first();
    expect($fresh->description)->toBe('The business in its own words.');
    expect($fresh->field_sources['description']['source'])->toBe('website-scan');
});

it('overwrites its own earlier plain website-scan description with richer prose', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $workplace = Workplace::create(['site_id' => (string) $site->id, 'description' => 'A short meta description.']);
    $workplace->forceFill(['field_sources' => ['description' => ['source' => 'website-scan', 'at' => now()->toIso8601String()]]])->save();

    app(WorkplaceContentApplier::class)->applyProseDescription($workplace, 'A much richer paragraph of authored prose.');

    expect(Workplace::where('site_id', (string) $site->id)->first()->description)->toBe('A much richer paragraph of authored prose.');
});

it('never overwrites a manually-set description', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $workplace = Workplace::create(['site_id' => (string) $site->id, 'description' => 'Owner-written description.']);
    // field_sources is system-written, not in $fillable — forceFill bypasses
    // mass-assignment protection, same convention IdentitySyncTest uses.
    $workplace->forceFill(['field_sources' => ['description' => ['source' => 'manual', 'at' => now()->toIso8601String()]]])->save();

    app(WorkplaceContentApplier::class)->applyProseDescription($workplace, 'Scraped prose that should never land.');

    expect(Workplace::where('site_id', (string) $site->id)->first()->description)->toBe('Owner-written description.');
});

it('does nothing for applyProseDescription when given null or blank text', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $workplace = Workplace::create(['site_id' => (string) $site->id, 'description' => 'Existing.']);

    app(WorkplaceContentApplier::class)->applyProseDescription($workplace, null);
    app(WorkplaceContentApplier::class)->applyProseDescription($workplace, '   ');

    expect(Workplace::where('site_id', (string) $site->id)->first()->description)->toBe('Existing.');
});

// ── applyContactEmail() — fill-if-empty ───────────────────────────────────────

it('fills a blank contact_email, stamping field_sources provenance', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $workplace = Workplace::create(['site_id' => (string) $site->id]);

    app(WorkplaceContentApplier::class)->applyContactEmail($workplace, 'owner@example.com');

    $fresh = Workplace::where('site_id', (string) $site->id)->first();
    expect($fresh->contact_email)->toBe('owner@example.com');
    expect($fresh->field_sources['contact_email']['source'])->toBe('website-scan');
});

it('never overwrites an existing contact_email', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $workplace = Workplace::create(['site_id' => (string) $site->id, 'contact_email' => 'manual@example.com']);

    app(WorkplaceContentApplier::class)->applyContactEmail($workplace, 'scraped@example.com');

    expect(Workplace::where('site_id', (string) $site->id)->first()->contact_email)->toBe('manual@example.com');
});

it('does nothing for applyContactEmail when given null or blank text', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    $workplace = Workplace::create(['site_id' => (string) $site->id]);

    app(WorkplaceContentApplier::class)->applyContactEmail($workplace, null);
    app(WorkplaceContentApplier::class)->applyContactEmail($workplace, '   ');

    expect(Workplace::where('site_id', (string) $site->id)->first()->contact_email)->toBeNull();
});
