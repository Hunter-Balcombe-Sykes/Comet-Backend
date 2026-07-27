<?php

use App\Catalog\Detector;
use App\Catalog\Enums\EvidenceSurface;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\IdentifierSource;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('rejects a surface key with no dot', function () {
    expect(fn () => new Surface(
        key: 'tiktokprofile',
        brandKey: 'tiktok',
        displayName: 'TikTok',
        routingClass: RoutingClass::Link,
        shelf: Shelf::Video,
        identifierKind: IdentifierKind::Handle,
        refreshIntervalSecs: 0,
        capabilities: [],
        canonicalUrlTemplate: null,
        defaultSections: [],
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects a surface key with more than one dot', function () {
    expect(fn () => new Surface(
        key: 'tiktok.profile.extra',
        brandKey: 'tiktok',
        displayName: 'TikTok',
        routingClass: RoutingClass::Link,
        shelf: Shelf::Video,
        identifierKind: IdentifierKind::Handle,
        refreshIntervalSecs: 0,
        capabilities: [],
        canonicalUrlTemplate: null,
        defaultSections: [],
    ))->toThrow(InvalidArgumentException::class);
});

it('accepts a surface key with exactly one dot', function () {
    $surface = new Surface(
        key: 'tiktok.profile',
        brandKey: 'tiktok',
        displayName: 'TikTok',
        routingClass: RoutingClass::Link,
        shelf: Shelf::Video,
        identifierKind: IdentifierKind::Handle,
        refreshIntervalSecs: 0,
        capabilities: [],
        canonicalUrlTemplate: null,
        defaultSections: [],
    );

    expect($surface->key)->toBe('tiktok.profile');
});

it('computes a stable Detector id for identical inputs', function () {
    $id1 = Detector::computeId(
        surfaceKey: 'tiktok.profile',
        signalKey: null,
        evidence: EvidenceSurface::Url,
        registrableKey: 'tiktok.com',
        subdomainPattern: null,
        pathPattern: '#^/@(?<handle>[\w.-]+)/?$#',
        queryRequires: [],
        fingerprint: null,
    );

    $id2 = Detector::computeId(
        surfaceKey: 'tiktok.profile',
        signalKey: null,
        evidence: EvidenceSurface::Url,
        registrableKey: 'tiktok.com',
        subdomainPattern: null,
        pathPattern: '#^/@(?<handle>[\w.-]+)/?$#',
        queryRequires: [],
        fingerprint: null,
    );

    expect($id1)->toBe($id2)
        ->and($id1)->toMatch('/^[0-9a-f]{16}$/');
});

it('changes the Detector id when pathPattern changes', function () {
    $original = Detector::computeId(
        surfaceKey: 'tiktok.profile',
        signalKey: null,
        evidence: EvidenceSurface::Url,
        registrableKey: 'tiktok.com',
        subdomainPattern: null,
        pathPattern: '#^/@(?<handle>[\w.-]+)/?$#',
        queryRequires: [],
        fingerprint: null,
    );

    $changed = Detector::computeId(
        surfaceKey: 'tiktok.profile',
        signalKey: null,
        evidence: EvidenceSurface::Url,
        registrableKey: 'tiktok.com',
        subdomainPattern: null,
        pathPattern: '#^/different-pattern$#',
        queryRequires: [],
        fingerprint: null,
    );

    expect($changed)->not->toBe($original);
});

it('throws building a detector with neither surfaceKey nor signalKey', function () {
    expect(fn () => Detector::url('tiktok.com')->build(null))
        ->toThrow(InvalidArgumentException::class);
});

it('throws building a detector with both surfaceKey and signalKey', function () {
    expect(fn () => Detector::url('tiktok.com')->signal('some-signal')->build('tiktok.profile'))
        ->toThrow(InvalidArgumentException::class);
});

it('builds a full TikTok-like surface via SurfaceBuilder with a path-capture detector', function () {
    $surface = SurfaceBuilder::for('tiktok.profile')
        ->displayName('TikTok')
        ->routing(RoutingClass::Link)
        ->shelf(Shelf::Video)
        ->identifier(IdentifierKind::Handle)
        ->refreshEvery(0)
        ->connect('TikTokConnect')
        ->normalize('TikTokNormalizer')
        ->detect(
            Detector::url('tiktok.com')
                ->path('#^/@(?<handle>[\w.-]+)/?$#')
                ->captures('handle')
                ->from(IdentifierSource::Path)
        )
        ->build();

    expect($surface)->toBeInstanceOf(Surface::class)
        ->and($surface->key)->toBe('tiktok.profile')
        ->and($surface->brandKey)->toBe('tiktok')
        ->and($surface->detectors)->toHaveCount(1)
        ->and($surface->isLinkOnly())->toBeTrue();

    $array = $surface->toArray();

    expect($array['key'])->toBe('tiktok.profile')
        ->and($array['routing_class'])->toBe('link')
        ->and($array['shelf'])->toBe('video')
        ->and($array['identifier_kind'])->toBe('handle')
        ->and($array['lifecycle'])->toBe('active')
        ->and($array['detectors'][0]['evidence'])->toBe('url')
        ->and($array['detectors'][0]['identifier_source'])->toBe('path')
        ->and($array['detectors'][0]['identifier_capture'])->toBe('handle')
        ->and($array['detectors'][0]['strength'])->toBe(50);
});

it('reports isLinkOnly false once a fetch capability is present', function () {
    $surface = SurfaceBuilder::for('spotify.embed')
        ->displayName('Spotify')
        ->routing(RoutingClass::Content)
        ->shelf(Shelf::Music)
        ->identifier(IdentifierKind::Url)
        ->fetch('SpotifyFetch')
        ->build();

    expect($surface->isLinkOnly())->toBeFalse();
});

it('spot-checks EvidenceSurface weight for the extremes', function () {
    expect(EvidenceSurface::Url->weight())->toBe(100)
        ->and(EvidenceSurface::MetaTag->weight())->toBe(20);
});

it('derives brandKey from the key prefix when brand() is not called', function () {
    $surface = SurfaceBuilder::for('tiktok.profile')
        ->displayName('TikTok')
        ->routing(RoutingClass::Link)
        ->shelf(Shelf::Video)
        ->identifier(IdentifierKind::Handle)
        ->build();

    expect($surface->brandKey)->toBe('tiktok');
});
