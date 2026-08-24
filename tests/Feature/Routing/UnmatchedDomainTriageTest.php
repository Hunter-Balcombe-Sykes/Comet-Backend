<?php

use App\Catalog\UnmatchedDomains;
use App\Routing\Iri;
use App\Routing\LinkObserver;
use App\Routing\Placement;
use App\Routing\Projection;
use App\Routing\RoutingContext;
use App\Routing\Verdict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// catalog.unmatched_domains — the rot/triage queue. A domain the router could
// not place is the single best signal for "which detector should we write
// next?", and until now that signal was discarded: the table shipped as bare
// DDL on 2026-07-27 with no writer.
//
// The privacy contract is written into the migration and is the reason this is
// a purpose-built writer rather than a copy of the link_observations insert:
// "registrable key + masked path shape ONLY — never full paths or query
// strings". routing.link_observations deliberately keeps the raw URL (it is
// user-scoped, cascade-deleted with the account, and R3 added the FK that
// makes that true). This table is NOT user-scoped — it is a global aggregate
// with no user_id and therefore no deletion route — so anything identifying
// that lands here would survive the account it came from. Hence: no user id,
// no query string, no raw path.

beforeEach(function () {
    setupCatalogRuntimeTables();
});

function unmatchedIri(string $host, string $path = '/', array $query = []): Iri
{
    return new Iri(
        raw: 'https://'.$host.$path.($query === [] ? '' : '?'.http_build_query($query)),
        canonical: 'https://'.$host.$path,
        scheme: 'https',
        host: $host,
        registrableKey: $host,
        subdomain: null,
        path: $path,
        query: $query,
        port: null,
    );
}

it('files an unrecognised domain for triage', function () {
    app(UnmatchedDomains::class)->record(
        unmatchedIri('some-new-booking-tool.com', '/book/studio-a'),
        Projection::none('unknown-domain'),
    );

    $row = DB::connection('pgsql')->table('catalog.unmatched_domains')->first();
    expect($row->registrable_key)->toBe('some-new-booking-tool.com');
    expect((int) $row->hits)->toBe(1);
});

it('counts repeat sightings on one row instead of growing the queue', function () {
    $service = app(UnmatchedDomains::class);
    $service->record(unmatchedIri('some-new-booking-tool.com', '/book/a'), Projection::none('unknown-domain'));
    $service->record(unmatchedIri('some-new-booking-tool.com', '/book/b'), Projection::none('unknown-domain'));
    $service->record(unmatchedIri('some-new-booking-tool.com', '/book/c'), Projection::none('unknown-domain'));

    $rows = DB::connection('pgsql')->table('catalog.unmatched_domains')->get();
    expect($rows)->toHaveCount(1);
    // hits is what ranks the queue — it is the whole reason to triage one
    // domain before another, so an overwrite-instead-of-increment would make
    // the table useless while still looking populated.
    expect((int) $rows->first()->hits)->toBe(3);
});

it('keeps the first sighting date while moving the last', function () {
    $service = app(UnmatchedDomains::class);

    $this->travelTo(now()->subDays(10));
    $service->record(unmatchedIri('rot.example', '/x'), Projection::none('unknown-domain'));

    $this->travelBack();
    $service->record(unmatchedIri('rot.example', '/x'), Projection::none('unknown-domain'));

    $row = DB::connection('pgsql')->table('catalog.unmatched_domains')->first();
    // first_seen_at answers "how long have we been missing this?" — an upsert
    // that overwrote it would make every domain look new.
    expect(Carbon\Carbon::parse($row->first_seen_at)->isBefore(now()->subDays(9)))->toBeTrue();
    expect(Carbon\Carbon::parse($row->last_seen_at)->isAfter(now()->subMinute()))->toBeTrue();
});

it('separates a domain we have no rule for from one whose rules all missed', function () {
    $service = app(UnmatchedDomains::class);
    $service->record(unmatchedIri('never-heard-of-it.com', '/x'), Projection::none('unknown-domain'));
    $service->record(unmatchedIri('linktr.ee', '/x'), Projection::none('no-rule-matched'));

    $rows = DB::connection('pgsql')->table('catalog.unmatched_domains')
        ->orderBy('registrable_key')->get()->keyBy('registrable_key');

    // The two need completely different work: one needs a detector written,
    // the other needs an existing detector's patterns fixed. That is the whole
    // point of the has_detectors column.
    expect((bool) $rows['linktr.ee']->has_detectors)->toBeTrue();
    expect((bool) $rows['never-heard-of-it.com']->has_detectors)->toBeFalse();
});

it('records a path SHAPE, never the path itself and never the query string', function () {
    // The privacy contract. A booking URL's path segment is frequently a
    // person: /book/jane-smith-massage, ?client=jane%40example.com.
    app(UnmatchedDomains::class)->record(
        unmatchedIri('bookings.example', '/book/jane-smith-3f9a2b/confirm', ['client' => 'jane@example.com']),
        Projection::none('unknown-domain'),
    );

    $shape = DB::connection('pgsql')->table('catalog.unmatched_domains')->value('sample_path_shape');

    expect($shape)->not->toContain('jane');
    expect($shape)->not->toContain('3f9a2b');
    expect($shape)->not->toContain('example.com');
    expect($shape)->not->toContain('?');
    // Still useful: the static segments are what tell a triager this is a
    // booking flow rather than a product page.
    expect($shape)->toContain('book');
});

it('bounds the shape so a pathological URL cannot become the row', function () {
    config(['partna.catalog.unmatched_path_shape_segments' => 3]);

    app(UnmatchedDomains::class)->record(
        unmatchedIri('deep.example', '/a/b/c/d/e/f/g/h'),
        Projection::none('unknown-domain'),
    );

    $shape = DB::connection('pgsql')->table('catalog.unmatched_domains')->value('sample_path_shape');
    expect(substr_count($shape, '/'))->toBeLessThanOrEqual(4);
});

it('does not file a link the router successfully placed', function () {
    app(UnmatchedDomains::class)->record(
        unmatchedIri('acuity.com', '/schedule/abc'),
        new Projection('acuity.book', 'acuity-primary', [], 95, 40, 'abc', null),
    );

    expect(DB::connection('pgsql')->table('catalog.unmatched_domains')->count())->toBe(0);
});

it('skips a URL with no registrable key rather than writing a null row', function () {
    // registrable_key is the PRIMARY KEY. An unroutable input (mailto:,
    // an IP literal, something the suffix list rejected) has none.
    $iri = Iri::reject('mailto:someone@example.com', 'unroutable');

    app(UnmatchedDomains::class)->record($iri, Projection::none('unroutable'));

    expect(DB::connection('pgsql')->table('catalog.unmatched_domains')->count())->toBe(0);
});

it('stays silent when the catalog schema is simply absent', function () {
    // Same reasoning as DetectorSuspensions: production has no catalog schema,
    // and this write runs on every unplaced link. Warning would turn one
    // documented, intended state into a per-paste log flood.
    DB::connection('pgsql')->statement('DROP TABLE catalog.unmatched_domains');
    Log::spy();

    app(UnmatchedDomains::class)->record(
        unmatchedIri('some-new-booking-tool.com', '/book/a'),
        Projection::none('unknown-domain'),
    );

    Log::shouldNotHaveReceived('warning');
});

it('never fails the paste when the triage table is unavailable', function () {
    // Production has no catalog schema. A diagnostic aggregate must never be
    // the reason a user's link does not save — same posture as LinkObserver,
    // whose own writes are best-effort by design.
    DB::connection('pgsql')->statement('DROP TABLE catalog.unmatched_domains');

    $thrown = null;
    try {
        app(UnmatchedDomains::class)->record(
            unmatchedIri('some-new-booking-tool.com', '/book/a'),
            Projection::none('unknown-domain'),
        );
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull($thrown === null ? '' : 'the triage write escaped: '.$thrown->getMessage());
});

it('files the domain as a side effect of the router observing the link', function () {
    // The seam. LinkObserver::record() already runs on every route() call and
    // already holds both the Iri and the Projection, so the triage row costs
    // no extra plumbing — but without this call the writer above is a service
    // nothing invokes, which is the state the table shipped in.
    setupRoutingTables();

    app(LinkObserver::class)->record(
        unmatchedIri('some-new-booking-tool.com', '/book/studio-a'),
        Projection::none('unknown-domain'),
        new Placement(Verdict::Note, null, null, null, 'unrecognised'),
        new RoutingContext(null, 'paste'),
    );

    expect(DB::connection('pgsql')->table('catalog.unmatched_domains')
        ->where('registrable_key', 'some-new-booking-tool.com')->exists())->toBeTrue();
});

it('still records the observation when the triage write fails', function () {
    // Ordering property: the diagnostic aggregate must not be able to take
    // down the diagnostic ledger. routing.link_observations is what
    // `routing:reproject` replays, so losing it costs far more than losing a
    // triage row.
    setupRoutingTables();
    DB::connection('pgsql')->statement('DROP TABLE catalog.unmatched_domains');

    $id = app(LinkObserver::class)->record(
        unmatchedIri('some-new-booking-tool.com', '/book/studio-a'),
        Projection::none('unknown-domain'),
        new Placement(Verdict::Note, null, null, null, 'unrecognised'),
        new RoutingContext(null, 'paste'),
    );

    expect($id)->not->toBeNull();
    expect(DB::connection('pgsql')->table('routing.link_observations')->count())->toBe(1);
});
