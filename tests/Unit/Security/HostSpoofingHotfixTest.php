<?php

use App\Models\Core\MediaVariant;
use App\Models\Core\Site\SiteMedia;
use App\Services\Http\SafeUrlException;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\OpenTableService;
use App\Services\Platforms\ProviderDetector;
use App\Services\Platforms\WebsiteLinkHarvester;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// ── 2026-07-27 P0 hotfix: open-ended `[a-z.]+$` host suffixes let
// `brand.<attacker-domain>` pass as the brand. Every pattern is now a closed
// enumeration; these tests pin both directions (spoof rejected, real accepted).

it('rejects spoofed brand hosts in WebsiteLinkHarvester::classify()', function (string $url) {
    expect(app(WebsiteLinkHarvester::class)->classify($url))->toBeNull();
})->with([
    'https://www.opentable.evil.com/r/doc-pizza',
    'https://thefork.attacker.io/restaurant/x',
    'https://quandoo.phish.net/place/1',
    'https://deliveroo.evil.co/menu/x',
    'https://justeat.attacker.com/restaurants-x',
    'https://treatwell.evil.org/place/x',
    'https://www.eventbrite.evil.com/o/organiser-123',
    'https://ticketmaster.phish.io/event/x',
]);

it('still classifies real brand hosts in WebsiteLinkHarvester::classify()', function (string $url, string $platform) {
    expect(app(WebsiteLinkHarvester::class)->classify($url))
        ->not->toBeNull()
        ->and(app(WebsiteLinkHarvester::class)->classify($url)['platform'])->toBe($platform);
})->with([
    ['https://www.opentable.com.au/r/doc-pizza', 'opentable'],
    ['https://www.thefork.com.au/restaurant/some-place', 'reservations'],
    ['https://www.quandoo.com.au/place/some-place-1234', 'reservations'],
    ['https://deliveroo.co.uk/menu/london/x', 'online-ordering'],
    ['https://www.just-eat.co.uk/restaurants-x', 'online-ordering'],
    ['https://www.treatwell.co.uk/place/x', 'booking'],
    ['https://www.eventbrite.com.au/o/organiser-1234', 'eventbrite'],
    ['https://www.ticketmaster.com.au/event/x', 'events-custom'],
]);

it('rejects spoofed OpenTable hosts end to end', function () {
    $svc = app(OpenTableService::class);

    expect($svc->isOpenTableUrl('https://www.opentable.evil.com/r/x'))->toBeFalse()
        ->and($svc->parseRid('https://www.opentable.evil.com/restaurant/profile/123'))->toBeNull()
        // A bare ?rid= on a non-OpenTable host must no longer parse — the rid
        // drives the reserve widget, so it may only come from a real host.
        ->and($svc->parseRid('https://random-site.com/?rid=555'))->toBeNull()
        ->and($svc->isOpenTableUrl('https://www.opentable.com.au/r/doc-pizza'))->toBeTrue()
        ->and($svc->parseRid('https://www.opentable.com.au/restaurant/profile/123'))->toBe('123')
        ->and($svc->parseRid('https://www.opentable.com.au/x?rid=555'))->toBe('555');
});

it('rejects spoofed hosts in the platform registry detectors', function () {
    $detector = app(ProviderDetector::class);

    expect($detector->detectFor('events', 'https://www.eventbrite.evil.com/o/organiser-123'))->toBeNull()
        ->and($detector->detectFor('events', 'https://www.eventbrite.com.au/o/organiser-123'))->toBe('eventbrite')
        ->and($detector->detectFor('reservations', 'https://quandoo.phish.net/place/1'))->toBeNull()
        ->and($detector->detectFor('reservations', 'https://www.quandoo.com.au/place/some-place-1234'))->toBe('quandoo');
});

// ── SafeUrlFetcher own-infrastructure denylist ────────────────────────────────

it('refuses own-infrastructure hosts before any DNS resolution', function (string $url) {
    expect(fn () => app(SafeUrlFetcher::class)->assertSafe($url))
        ->toThrow(SafeUrlException::class);
})->with([
    'https://partna.au/',
    'https://dev-api.partna.au/api/internal/x',
    'https://anything.supabase.co/rest/v1/users',
    'https://env.laravel.cloud/x',
    'https://bucket.r2.cloudflarestorage.com/x',
    'https://pub-abc123.r2.dev/x',
    'https://worker.partna.workers.dev/x',
]);

it('still accepts a public literal IP after the denylist', function () {
    expect(fn () => app(SafeUrlFetcher::class)->assertSafe('http://1.1.1.1/'))
        ->not->toThrow(SafeUrlException::class);
});

// ── svgVariantUrl(): never the unsanitised original ───────────────────────────

it('never serves the original SVG upload when no sanitised vector variant exists', function () {
    $media = new SiteMedia([
        'pool' => SiteMedia::POOL_DESIGN,
        'path' => 'sites/x/design/original_deadbeef.svg',
        'original_mime' => 'image/svg+xml',
    ]);
    $media->setRelation('mediaVariants', collect());

    expect($media->svgVariantUrl())->toBeNull();
});

it('serves the sanitised vector variant when one exists', function () {
    $variant = new MediaVariant([
        'variant_key' => 'vector',
        'artifact_type' => 'svg',
        'disk' => 'public',
        'path' => 'sites/x/design/vector_cafebabe.svg',
    ]);

    $media = new SiteMedia([
        'pool' => SiteMedia::POOL_DESIGN,
        'path' => 'sites/x/design/original_deadbeef.svg',
        'original_mime' => 'image/svg+xml',
    ]);
    $media->setRelation('mediaVariants', collect([$variant]));

    expect($media->svgVariantUrl())->toBe($variant->url);
});
