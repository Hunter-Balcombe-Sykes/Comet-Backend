<?php

use App\Models\Core\MediaVariant;
use App\Models\Core\Site\SiteMedia;
use App\Services\Http\SafeUrlException;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\OpenTableService;
use App\Services\Platforms\ProviderDetector;
use App\Services\Platforms\WebsiteLinkHarvester;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/**
 * Read the real TLDS alternation off the production constant (never a
 * hand-copied duplicate, or this drifts silently the moment either list is
 * edited) and split it into individual suffixes for a per-TLD data provider.
 * ReflectionClass::getConstant() only reads a value — no private method is
 * invoked, so this isn't the direct-controller-call / private-method-poke
 * antipattern this codebase avoids elsewhere.
 *
 * @return list<string>
 */
function tldsFromConstant(string $class): array
{
    $raw = (new ReflectionClass($class))->getConstant('TLDS');

    return array_map(
        fn (string $tld) => str_replace('\\.', '.', $tld),
        explode('|', preg_replace('~^\(\?:|\)$~', '', $raw)),
    );
}

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
    // Convergence Phase 6 retired the shared booking/reservations/online-ordering
    // pseudo-platforms, so each brand now names itself. A REGISTERED brand keeps
    // its legacy slug; a CATALOG-ONLY brand (one added after P1, which can never
    // get a legacy slug — LegacyPlatformMap is frozen to the backfill migration)
    // is named by its surface key. This test's subject is host SPOOFING, and that
    // half is unchanged above: what matters here is only that a genuine host
    // still resolves to something.
    ['https://www.opentable.com.au/r/doc-pizza', 'opentable'],
    ['https://www.thefork.com.au/restaurant/some-place', 'thefork.reserve'],
    ['https://www.quandoo.com.au/place/some-place-1234', 'quandoo'],
    ['https://deliveroo.co.uk/menu/london/x', 'deliveroo.order'],
    ['https://www.just-eat.co.uk/restaurants-x', 'just_eat.order'],
    ['https://www.treatwell.co.uk/place/x', 'treatwell.book'],
    ['https://www.eventbrite.com.au/o/organiser-1234', 'eventbrite'],
    // events-parity 2026-08-19: the events arms answer with the REAL brand
    // key, not the retired 'events-custom' pseudo-slug.
    ['https://www.ticketmaster.com.au/event/x', 'ticketmaster'],
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

// ── #TEST-12: per-TLD coverage over the closed enumerations ─────────────────
//
// Not busywork: EventbriteScraper::TLDS / OpenTableService::TLDS put `com`
// BEFORE `com\.au` (etc.) in the alternation, so a multi-label suffix like
// `.com.au` only matches via PCRE backtracking — the engine tries `com`
// first, fails the anchor/literal that follows, and backtracks into trying
// `com\.au` next. An atomic group, a possessive quantifier, or someone
// "tidying" the alternation into a trie can silently disable that
// backtracking: every multi-label TLD (`com.au`, `co.uk`, `com.br`, …) then
// stops matching while the single-label ones (`.com`, `.de`) stay green —
// exactly the kind of regression a same-shaped negative test can't catch.

it('accepts every TLD enumerated in EventbriteScraper::TLDS via normalizeOrgUrl', function (string $tld) {
    expect(app(EventbriteScraper::class)->normalizeOrgUrl("https://www.eventbrite.{$tld}/o/some-organiser-123"))
        ->toBe("https://www.eventbrite.{$tld}/o/some-organiser-123");
})->with(tldsFromConstant(EventbriteScraper::class));

it('accepts every TLD enumerated in OpenTableService::TLDS via isOpenTableUrl', function (string $tld) {
    expect(app(OpenTableService::class)->isOpenTableUrl("https://www.opentable.{$tld}/r/some-restaurant"))->toBeTrue();
})->with(tldsFromConstant(OpenTableService::class));

it('anchors the OpenTable/Eventbrite host checks — a spoof suffix never sneaks past a dropped ^ or $', function () {
    // Deliberately routed through isOpenTableUrl()/normalizeOrgUrl(), NOT
    // parseRid()/nameFromUrl() — those two are a DOCUMENTED, non-exploitable
    // latent gap (unanchored, but every caller gates on isOpenTableUrl()
    // first) and asserting null through them would be a red test against a
    // known, filed non-defect, not a real regression guard.
    $opentable = app(OpenTableService::class);
    $eventbrite = app(EventbriteScraper::class);

    // Trailing-$ case: the extra ".evil.com" after a real-looking TLD must
    // still fail the host gate.
    expect($opentable->isOpenTableUrl('https://opentable.com.evil.com/r/x'))->toBeFalse()
        // Leading-(^|.)-case: "opentable" as a mid-label substring of another
        // word must not satisfy the host-boundary anchor.
        ->and($opentable->isOpenTableUrl('https://notopentable.com/r/x'))->toBeFalse()
        // "opentable" appearing only in the PATH of an unrelated host must
        // never register as an OpenTable host.
        ->and($opentable->isOpenTableUrl('https://evil.com/opentable.com/r/x'))->toBeFalse()
        ->and($eventbrite->normalizeOrgUrl('https://eventbrite.com.evil.com/o/organiser-123'))->toBeNull();
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
