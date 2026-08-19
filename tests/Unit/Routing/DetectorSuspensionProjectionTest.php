<?php

use App\Routing\Iri;
use App\Routing\LinkProjector;
use App\Routing\Rulepack;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// catalog.detector_suspensions is the staff kill-switch for one detector. It
// shipped as bare DDL on 2026-07-27 and nothing ever read it, so the switch
// was decorative: a suspended detector went on placing connections.
//
// The suspension set is carried ON the Rulepack rather than read from the
// database inside project(). That is deliberate and load-bearing: the
// projector's contract is f(Iri, Rulepack) -> Projection with no I/O, which is
// what makes `routing:reproject` a real diff tool. Resolving suspensions when
// the Rulepack singleton is built keeps the projector pure and keeps a
// suspension out of the per-URL hot path.
//
// The reason code is the subtle half. A suspended detector must still count as
// a rule that EXISTS — 'no-rule-matched', not 'unknown-domain'. Those two
// strings are what catalog.unmatched_domains.has_detectors is derived from, so
// getting this wrong would file every suspended brand in the triage queue as an
// undiscovered domain and send someone off to write a detector that is already
// written.

// Uniquely prefixed: unnamespaced Pest files share one global symbol table.
function suspRulepack(array $suspended = []): Rulepack
{
    $detector = [
        'surface_key' => 'acuity.book',
        'signal_key' => null,
        'evidence' => 'url',
        'registrable_key' => 'acuity.com',
        'subdomain_pattern' => null,
        'path_pattern' => '#^/schedule/(?<slug>[a-z0-9]+)$#',
        'query_requires' => [],
        'fingerprint' => null,
        'identifier_capture' => 'slug',
        'identifier_source' => 'path',
        'probe_capability' => null,
        'strength' => 50,
        'reject_patterns' => [],
        'markets' => [],
        'note' => null,
    ];

    return new Rulepack(
        byRegistrableKey: ['acuity.com' => ['acuity-primary', 'acuity-secondary']],
        detectors: [
            'acuity-primary' => $detector,
            // Same host, different rule — proves a suspension is per DETECTOR,
            // not per registrable key.
            'acuity-secondary' => ['path_pattern' => '#^/book/(?<slug>[a-z0-9]+)$#'] + $detector,
        ],
        detectorSurface: [
            'acuity-primary' => 'acuity.book',
            'acuity-secondary' => 'acuity.book',
        ],
        catalogDigest: 'sha256:synthetic',
        suspended: $suspended,
    );
}

function suspIri(string $path): Iri
{
    return new Iri(
        raw: 'https://acuity.com'.$path,
        canonical: 'https://acuity.com'.$path,
        scheme: 'https',
        host: 'acuity.com',
        registrableKey: 'acuity.com',
        subdomain: null,
        path: $path,
        query: [],
        port: null,
    );
}

it('matches normally when nothing is suspended', function () {
    $projection = (new LinkProjector(suspRulepack()))->project(suspIri('/schedule/abc123'));

    expect($projection->matched())->toBeTrue();
    expect($projection->detectorId)->toBe('acuity-primary');
});

it('does not place a link through a suspended detector', function () {
    $projection = (new LinkProjector(suspRulepack(['acuity-primary'])))->project(suspIri('/schedule/abc123'));

    expect($projection->matched())->toBeFalse();
    expect($projection->surfaceKey)->toBeNull();
    expect($projection->detectorId)->toBeNull();
});

it('reports a suspended detector as no-rule-matched, never unknown-domain', function () {
    // The distinction catalog.unmatched_domains.has_detectors is built from.
    // 'unknown-domain' here would mean "nobody has written a rule for this
    // host" — a false statement about a host we have two rules for.
    $projection = (new LinkProjector(suspRulepack(['acuity-primary', 'acuity-secondary'])))
        ->project(suspIri('/schedule/abc123'));

    expect($projection->matched())->toBeFalse();
    expect($projection->reason)->toBe('no-rule-matched');
});

it('suspends one detector without disarming its neighbours on the same host', function () {
    $projector = new LinkProjector(suspRulepack(['acuity-primary']));

    // The suspended rule's own URL shape is dead...
    expect($projector->project(suspIri('/schedule/abc123'))->matched())->toBeFalse();
    // ...while the sibling rule on the identical registrable key still fires.
    $sibling = $projector->project(suspIri('/book/xyz789'));
    expect($sibling->matched())->toBeTrue();
    expect($sibling->detectorId)->toBe('acuity-secondary');
});

it('leaves the compiled rulepack untouched when suspensions are applied', function () {
    // withSuspensions() is how the container binding layers runtime state onto
    // the compiled artefact. Rulepack is readonly and shared as a singleton, so
    // this must copy rather than mutate — otherwise `catalog:compile` and
    // `routing:reproject`, which want the pure pack, would inherit whatever the
    // web tier last resolved.
    $pure = suspRulepack();
    $suspendedPack = $pure->withSuspensions(['acuity-primary']);

    expect($pure->isSuspended('acuity-primary'))->toBeFalse();
    expect($suspendedPack->isSuspended('acuity-primary'))->toBeTrue();
    expect($suspendedPack->byRegistrableKey)->toBe($pure->byRegistrableKey);
    expect($suspendedPack->catalogDigest)->toBe($pure->catalogDigest);
});
