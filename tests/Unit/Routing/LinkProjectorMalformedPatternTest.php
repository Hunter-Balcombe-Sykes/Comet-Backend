<?php

use App\Exceptions\Routing\MalformedDetectorPatternException;
use App\Routing\Iri;
use App\Routing\LinkProjector;
use App\Routing\Rulepack;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// SLOP-21. The compiled catalog can never contain an uncompilable pattern
// (CatalogCompileCommand rejects it, CatalogArtefactTest re-asserts it), so
// these cases have to be built by hand against a synthetic Rulepack.
//
// Two properties are under test and they pull in opposite directions:
//   1. an uncompilable pattern must NOT throw into the request path — the
//      projector is reached from the debounced paste preview, and PHP's
//      HandleExceptions would turn PCRE's compile warning into an
//      ErrorException if the `@` inside LinkProjector::matches() were removed;
//   2. it must not be silent either, because a `false` return is
//      indistinguishable from "this URL simply does not match".
//
// NOTE for anyone tempted to drop the `@`: PHPUnit installs its OWN error
// handler, so a green suite would not reveal that regression. Only property 1's
// explicit assertion below stands in for the live request path.

// Uniquely prefixed: unnamespaced Pest files share one global symbol table.
function slop21Projector(array $detector, string $registrableKey = 'acuity.com'): LinkProjector
{
    return new LinkProjector(new Rulepack(
        byRegistrableKey: [$registrableKey => ['synthetic-detector']],
        detectors: ['synthetic-detector' => $detector + [
            'surface_key' => 'acuity.book',
            'signal_key' => null,
            'evidence' => 'url',
            'registrable_key' => $registrableKey,
            'subdomain_pattern' => null,
            'path_pattern' => null,
            'query_requires' => [],
            'fingerprint' => null,
            'identifier_capture' => null,
            'identifier_source' => 'none',
            'probe_capability' => null,
            'strength' => 50,
            'reject_patterns' => [],
            'markets' => [],
            'note' => null,
        ]],
        // A real surface key: score() reads reserved_paths off the compiled
        // catalog, and acuity.book declares none.
        detectorSurface: ['synthetic-detector' => 'acuity.book'],
        catalogDigest: 'sha256:synthetic',
    ));
}

function slop21Iri(string $path = '/schedule/abc123', ?string $subdomain = null, string $registrableKey = 'acuity.com'): Iri
{
    return new Iri(
        raw: 'https://'.($subdomain === null ? '' : $subdomain.'.').$registrableKey.$path,
        canonical: 'https://'.($subdomain === null ? '' : $subdomain.'.').$registrableKey.$path,
        scheme: 'https',
        host: ($subdomain === null ? '' : $subdomain.'.').$registrableKey,
        registrableKey: $registrableKey,
        subdomain: $subdomain,
        path: $path,
        query: [],
        port: null,
    );
}

it('fails closed and reports when a detector ships a malformed regex, never throwing into the request path', function () {
    Exceptions::fake();

    // Unterminated character class — PCRE refuses to compile it.
    $projector = slop21Projector(['path_pattern' => '#^/schedule/(?<slug>[a-z0-9#']);

    $thrown = null;
    $projection = null;
    try {
        $projection = $projector->project(slop21Iri());
    } catch (Throwable $e) {
        $thrown = $e;
    }

    // Property 1: nothing escaped. Pre-fix this was true too (the `@`), and it
    // must stay true — this is the assertion that guards the paste preview.
    expect($thrown)->toBeNull($thrown === null ? '' : 'a malformed pattern escaped the projector: '.$thrown->getMessage());

    // Property 2 (verdict): byte-identical to the pre-fix behaviour.
    expect($projection->matched())->toBeFalse();
    expect($projection->surfaceKey)->toBeNull();
    expect($projection->reason)->toBe('no-rule-matched');

    // Property 2 (signal): the part that did not exist before.
    Exceptions::assertReported(MalformedDetectorPatternException::class);
    Exceptions::assertReported(fn (MalformedDetectorPatternException $e) => $e->detectorId === 'synthetic-detector'
        && $e->field === 'path_pattern');
});

it('reports a malformed pattern once per window, not once per paste', function () {
    Exceptions::fake();

    $projector = slop21Projector(['path_pattern' => '#^/schedule/(?<slug>[a-z0-9#']);

    // Two different URLs on the same broken detector: a real broken catalog
    // would hit this on every paste, and one entry must not flood Nightwatch.
    $projector->project(slop21Iri('/schedule/abc123'));
    $projector->project(slop21Iri('/schedule/zzz999'));

    Exceptions::assertReportedCount(1);
});

it('reports each malformed field separately, and reject patterns by index', function () {
    Exceptions::fake();

    $projector = slop21Projector([
        'reject_patterns' => ['#/ok/#', '#[unterminated#'],
        'path_pattern' => '#^/schedule/(?<slug>[a-z0-9#',
    ]);

    $projector->project(slop21Iri('/schedule/abc123'));

    // The window is keyed per detector+field, so a detector with two broken
    // patterns still surfaces both — one report each, not one for the detector.
    Exceptions::assertReportedCount(2);
    Exceptions::assertReported(fn (MalformedDetectorPatternException $e) => $e->field === 'reject_patterns[1]');
    Exceptions::assertReported(fn (MalformedDetectorPatternException $e) => $e->field === 'path_pattern');
});

it('claims its throttle key with a TTL, never forever', function () {
    // House rule (CacheKeyspaceConstraintsTest): every cache key expires.
    // Asserted behaviourally as well as statically — the key must be gone once
    // the window elapses, so a persistently-broken catalog re-reports.
    Exceptions::fake();
    config(['partna.routing.malformed_pattern_report_ttl_seconds' => 60]);

    $projector = slop21Projector(['path_pattern' => '#^/schedule/(?<slug>[a-z0-9#']);
    $projector->project(slop21Iri());

    expect(Cache::has('routing:malformed-detector:synthetic-detector:path_pattern'))->toBeTrue();

    $this->travel(61)->seconds();
    $projector->project(slop21Iri());

    Exceptions::assertReportedCount(2);
});

it('still returns preg_match named captures through the matches() wrapper', function () {
    // The by-reference $m is the exact spot a careless wrapper would silently
    // drop captures — identifier resolution and +35/+20 confidence both hang
    // off it, so a drop would quietly lower scores rather than fail loudly.
    $projector = slop21Projector([
        'subdomain_pattern' => '#^(?<tenant>[a-z0-9-]+)$#',
        'path_pattern' => '#^/schedule/(?<slug>[a-z0-9]+)$#',
        'identifier_capture' => 'slug',
        'identifier_source' => 'path',
    ]);

    $projection = $projector->project(slop21Iri('/schedule/abc123', 'studio'));

    expect($projection->matched())->toBeTrue();
    expect($projection->captures)->toBe(['tenant' => 'studio', 'slug' => 'abc123']);
    expect($projection->identifier)->toBe('abc123');
    // 40 base + 20 subdomain + 35 path + 0 strength shift.
    expect($projection->confidence)->toBe(95);
});

it('a matching reject pattern still short-circuits through the wrapper', function () {
    $projector = slop21Projector([
        'path_pattern' => '#^/schedule/(?<slug>[a-z0-9]+)$#',
        'reject_patterns' => ['#^/schedule/abc#'],
    ]);

    expect($projector->project(slop21Iri('/schedule/abc123'))->matched())->toBeFalse();
    expect($projector->project(slop21Iri('/schedule/xyz789'))->matched())->toBeTrue();
});
