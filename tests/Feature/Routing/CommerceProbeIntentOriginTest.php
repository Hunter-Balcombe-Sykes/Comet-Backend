<?php

// CommerceProbeJob::ORIGIN = 'commerce_probe', but routing.source_intents'
// origin CHECK shipped without that value, so every probe that resolved a
// store threw 23514 at SourceReconciler's intent insert. The probe itself
// succeeded; only the intent write failed, which is why it presented as
// missing routing history rather than as a broken probe.
//
// The two drivers fail differently, which is why this asserts the row LANDS
// rather than that nothing was thrown. On Postgres the insert raises 23514
// directly. On SQLite it does not: the insert goes through insertOrIgnore(),
// and INSERT OR IGNORE swallows CHECK violations as well as unique ones — so
// the row is silently skipped and it is SourceReconciler's own "Could not
// upsert source intent" invariant guard that converts the miss into a throw.
// An assertion phrased as "does not throw" would be testing two different
// mechanisms; "the row exists with this origin" is the one fact both drivers
// agree on.

use App\Jobs\Platforms\CommerceProbeJob;
use App\Routing\LinkRoutingService;
use App\Routing\RoutingContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    Queue::fake();
});

it('writes a source intent whose origin is the one CommerceProbeJob actually sends', function () {
    $pro = createTenant('commerce-probe-origin');

    // Read the literal off the job rather than retyping it: the point of the
    // test is that the constraint matches what the job sends, so a rename
    // there must move this test with it instead of leaving it passing.
    $origin = (new ReflectionClass(CommerceProbeJob::class))->getConstant('ORIGIN');
    expect($origin)->toBe('commerce_probe');

    $result = app(LinkRoutingService::class)->route(
        'https://www.instagram.com/commerce_probe_origin_user',
        RoutingContext::forUser($pro, $origin),
    );

    // 'choose', not 'place' — and that is the design, not a shortfall. Only
    // 'paste' is a direct request (RoutingContext::isDirectRequest()), so a
    // probe-originated link is proposed for the user rather than auto-placed.
    // CommerceProbeJob's ORIGIN docblock picked a non-'paste' literal for
    // exactly this reason: it keeps the probe tombstone-safe. Pinning the
    // verdict here means a change that quietly promoted probes to direct
    // requests fails this test instead of resurrecting removed links in prod.
    expect($result['verdict'])->toBe('choose');

    $intent = DB::table('routing.source_intents')->where('user_id', $pro->id)->first();

    expect($intent)->not->toBeNull()
        ->and($intent->origin)->toBe('commerce_probe')
        ->and($intent->state)->toBe('proposed');
});
