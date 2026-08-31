<?php

// "Is this Uber Eats URL a store?" is answered in TWO places, and they used to
// answer it differently:
//
//   Catalog/Definitions/UberEats.php   #^/(?:[a-z]{2}/)?store/(?<slug>…)/(?<id>…)#
//   config partna.menu.platforms       ~^/(?:[a-z]{2}(?:-[a-z]{2})?/)?store/~i
//
// so https://www.ubereats.com/en-AU/store/x/y was a store to the scrape guard
// and an unrecognised link to the detector. That asymmetry is not a safety
// margin — it means pasting an en-AU store URL scores host-only, lands under
// RoutingPolicy's 55-point ordering bar, and returns Verdict::Note instead of a
// connection. A lost store.
//
// The detector's locale alternation was widened to match (2026-09-01). The
// residual difference is deliberate and asserted below with its direction: the
// detector additionally demands /<slug>/<id> because it has to CAPTURE a store
// id to mint a connection, while the config key only has to reject pages with
// no menu behind them. Everything the detector accepts, the config accepts.
// Never the reverse-only case that started this.

use App\Catalog\Definitions\UberEats;
use Illuminate\Support\Facades\Config;

function ueDetectorPath(): string
{
    $surface = UberEats::surfaces()[0];
    $pattern = $surface->detectors[0]->pathPattern;

    expect($pattern)->toBeString();

    return (string) $pattern;
}

function ueConfigPath(): string
{
    return (string) Config::get('partna.menu.platforms.uber-eats.store_path_pattern');
}

/**
 * Paths both spellings are asked about. Real Uber Eats shapes plus the locale
 * forms that caused the disagreement.
 *
 * @return array<string, bool> path => is it a store page
 */
function uePathCorpus(): array
{
    return [
        // Stores. Every one of these must reach the scraper AND be connectable.
        '/au/store/st-ali/nK322' => true,
        '/store/blue-bottle/abc123' => true,
        '/gb/store/dishoom/xY9' => true,
        // The reviewer's URL — the whole reason this file exists.
        '/en-AU/store/x/y' => true,
        '/en-GB/store/dishoom/xY9' => true,
        // Not stores. The incident, and its neighbours on the same host.
        '/au/brand/guzman-y-gomez' => false,
        '/brand/guzman-y-gomez' => false,
        '/en-AU/brand/guzman-y-gomez' => false,
        '/au' => false,
        '/' => false,
        '/au/feed' => false,
        '/au/near-me' => false,
        // Look-alikes: 'store' must be a whole segment in its own right.
        '/au/storefront/x/y' => false,
        '/au/not-store/x/y' => false,
    ];
}

it('agrees with the catalog detector on every store path', function () {
    // One direction only, and it is the one that matters: a URL the detector
    // will mint a connection from must be a URL the scrape guard will scrape.
    // A connection that can never be scraped is precisely the guzman-y-gomez
    // shape, arrived at from the other end.
    foreach (uePathCorpus() as $path => $isStore) {
        if (preg_match(ueDetectorPath(), $path) !== 1) {
            continue;
        }
        expect(preg_match(ueConfigPath(), $path))->toBe(
            1,
            "detector accepts {$path} but the scrape guard rejects it — a connectable store that can never be scraped",
        );
    }
});

it('accepts every real store path in both spellings', function () {
    foreach (array_keys(array_filter(uePathCorpus())) as $path) {
        expect(preg_match(ueConfigPath(), $path))->toBe(1, "scrape guard rejects real store {$path}");
        expect(preg_match(ueDetectorPath(), $path))->toBe(1, "detector rejects real store {$path}");
    }
});

it('rejects every non-store path in both spellings', function () {
    foreach (array_keys(uePathCorpus(), false, true) as $path) {
        expect(preg_match(ueConfigPath(), $path))->toBe(0, "scrape guard accepts non-store {$path}");
        expect(preg_match(ueDetectorPath(), $path))->toBe(0, "detector accepts non-store {$path}");
    }
});

it('documents the one difference that is allowed to remain', function () {
    // The detector is stricter about SHAPE, never about locale: it needs the
    // <slug>/<id> pair to capture an identifier. '/au/store/x' is a store-ish
    // path with nothing to capture — scrapable, not connectable. If a future
    // edit makes the config key this strict too, ~30 existing fixtures using
    // '/store/x' stop resolving, so the asymmetry is load-bearing, not slack.
    expect(preg_match(ueConfigPath(), '/au/store/x'))->toBe(1)
        ->and(preg_match(ueDetectorPath(), '/au/store/x'))->toBe(0);
});
