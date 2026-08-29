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
use Illuminate\Support\Facades\RateLimiter;

// pinPoolPresence() lives in tests/Pest.php — shared with
// PresenceProbeEscalationTest.php, which needs the exact same trick.

it('tags a presence-probe failure with the services-probe label and the site/user id', function () {
    $pro = createTenant('probe-services');
    // setupSectionsTables() (not setupContentTables()) — provisions the pool
    // tables the P4 probes need, but leaves content.sources/content.source_items
    // absent, so the services probe (content.items x source_items x sources)
    // genuinely faults. pinPoolPresence() keeps the watch/listen/events pool
    // probes themselves clean (see its docblock) so the ONLY warning below
    // is the services probe's.
    setupSectionsTables();
    setupBlocksTable();
    setupMediaTables();
    pinPoolPresence($pro->site);

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
    // setupSectionsTables() (not setupContentTables()) — the services probe's
    // content.sources/content.source_items stay absent. Neither services nor
    // blocks exists — both probes must fault, each with its own label.
    // pinPoolPresence() keeps the watch/listen/events pool probes clean so
    // this test's count stays at exactly these two. Collected via a real
    // listener (not Log::spy's cardinality matcher) since this test asserts
    // on TWO distinct calls at once.
    setupSectionsTables();
    setupMediaTables();
    pinPoolPresence($pro->site);
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

it('tags a content-pool read fault with the new event name and its own escalation bucket', function () {
    // #W1-LIFE-5: the seven content-pool reads reuse safeQuery() via its new
    // optional $event parameter so they log under 'sitepage.content_read_failed'
    // instead of the presence-probe event — a fault here is a different FAMILY
    // of read (content, not presence) and must not be indistinguishable from
    // the 8 presence probes above in either the log stream or the escalation
    // counter.
    $pro = createTenant('probe-content-fault');
    setupSectionsTables();
    setupBlocksTable();
    // Deliberately no setupMediaTables() — getDocument()'s SiteMedia query
    // genuinely faults (missing site.site_media), same "missing table in a
    // partial test env" idiom as every other probe fault in this file.

    Log::spy();

    $result = app(SitepageDataResolverService::class)->getDocument($pro->site);

    expect($result)->toBe(['state' => 'draft', 'data' => null]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $ctx) => $message === 'sitepage.content_read_failed'
            && $ctx['probe'] === 'content_document'
            && $ctx['site_id'] === $pro->site->id
            && $ctx['user_id'] === $pro->id
            && is_string($ctx['error']) && $ctx['error'] !== '');

    // Escalation still runs through the shared trait, keyed on THIS probe's
    // own bucket (sitepage_probe_content_document) — distinct from every
    // presence-probe bucket above, so a sustained content-read outage pages
    // independently of a sustained presence-probe outage.
    expect(RateLimiter::attempts('analytics:fault:sitepage_probe_content_document'))->toBe(1);
});
