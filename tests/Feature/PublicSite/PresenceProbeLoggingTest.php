<?php

// LIFE-104: safeQuery's presence-probe log had no discriminator — all 8 call
// sites logged the same bare 'sitepage.presence_probe_failed' string with only
// the exception message, so a probe failure in production couldn't be traced
// to which of the 8 probes fired or for which site/user. These tests force a
// probe to genuinely fault (by omitting the table its query needs — the exact
// "missing table in a partial test env" scenario the safeQuery docblock
// describes) and assert the log context carries the SPECIFIC probe label +
// site_id + user_id, not just that "a warning was logged". Two different
// probes are exercised (and one combined case) to prove the labels are
// genuinely distinct, not a single hardcoded string.

use App\Services\Accounts\AccountCapabilities;
use App\Services\PublicSite\SitepageDataResolverService;
use Illuminate\Support\Facades\Log;

it('tags a presence-probe failure with the services-probe label and the site/user id', function () {
    $pro = createTenant('probe-services');
    setupContentTables(); // pool presence probes (P4) need the pool tables
    setupBlocksTable();
    setupMediaTables();
    // Deliberately no setupServicesTable() — Service::query() faults.

    Log::spy();

    app(SitepageDataResolverService::class)
        ->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $ctx) => $message === 'sitepage.presence_probe_failed'
            && $ctx['probe'] === 'active_services_exists'
            && $ctx['site_id'] === $pro->site->id
            && $ctx['user_id'] === $pro->id
            && is_string($ctx['error']) && $ctx['error'] !== '');
});

it('tags a presence-probe failure with a DIFFERENT label for the links probe', function () {
    $pro = createTenant('probe-links');
    setupContentTables(); // pool presence probes (P4) need the pool tables
    setupServicesTable();
    setupMediaTables();
    // Deliberately no setupBlocksTable() — the live-link-block Block::query() faults.

    Log::spy();

    app(SitepageDataResolverService::class)
        ->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $ctx) => $message === 'sitepage.presence_probe_failed'
            && $ctx['probe'] === 'live_link_block_exists'
            && $ctx['site_id'] === $pro->site->id
            && $ctx['user_id'] === $pro->id);
});

it('threads two distinct probe labels when two different probes fault in the same call, proving the labels are not hardcoded once', function () {
    $pro = createTenant('probe-multi');
    setupContentTables(); // pool presence probes (P4) need the pool tables
    setupMediaTables();
    // Neither services nor blocks exists — both probes must fault, each with
    // its own label. Collected via a real listener (not Log::spy's cardinality
    // matcher) since this test asserts on TWO distinct calls at once.
    $warnings = [];
    Log::listen(function ($event) use (&$warnings) {
        if ($event->level === 'warning' && $event->message === 'sitepage.presence_probe_failed') {
            $warnings[] = $event->context;
        }
    });

    app(SitepageDataResolverService::class)
        ->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($warnings)->toHaveCount(2);
    $probes = array_column($warnings, 'probe');
    expect($probes)->toContain('active_services_exists');
    expect($probes)->toContain('live_link_block_exists');
    // Both faults are on the SAME site — the discriminator is the probe label,
    // not incidentally-different site/user context.
    foreach ($warnings as $ctx) {
        expect($ctx['site_id'])->toBe($pro->site->id);
        expect($ctx['user_id'])->toBe($pro->id);
    }
});

it('tags a curated-gallery probe failure distinctly from the presentPageIds probes', function () {
    $pro = createTenant('probe-curated');
    setupContentTables(); // pool presence probes (P4) need the pool tables
    // Deliberately no setupContentSelectionTable() — ContentSelection::query() faults.

    Log::spy();

    $result = app(SitepageDataResolverService::class)->buildCuratedGalleryData($pro->site);

    expect($result)->toBe([]);
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $ctx) => $message === 'sitepage.presence_probe_failed'
            && $ctx['probe'] === 'curated_gallery_resolve'
            && $ctx['site_id'] === $pro->site->id
            && $ctx['user_id'] === $pro->id);
});
