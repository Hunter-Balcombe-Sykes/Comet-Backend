<?php

// Routing class has exactly ONE source: the compiled catalog artefact.
//
// LegacyPlatformMap used to carry a 72-entry ROUTING_CLASS const that answered
// FIRST, with the catalog only as a fallback. It agreed with the artefact
// everywhere (measured 2026-08-20: zero missing, zero disagreements across all
// 111 surfaces), so it bought nothing — but it gated every connection write via
// IntegrationConnection::booted(), which made CompiledCatalog's "the ONLY
// runtime source of platform identity" docblock false.
//
// The failure mode this guards is silent: a second table agrees with the
// artefact on the day it is written and drifts later, and the drift only
// surfaces as a mis-classified connection in production. A grep-shaped guard is
// the right weight — it catches a regression back to hand-rolled tables on a
// known-closed set of directories, and does not try to discover new ones.

use App\Catalog\CompiledCatalog;
use App\Catalog\LegacyPlatformMap;

/** Every routing class the app can name, per the artefact. */
function routingClassValues(): array
{
    return collect(CompiledCatalog::surfaces())
        ->pluck('routing_class')
        ->filter()
        ->unique()
        ->values()
        ->all();
}

it('resolves routing class only through the compiled catalog', function () {
    foreach (CompiledCatalog::surfaces() as $key => $surface) {
        expect(LegacyPlatformMap::routingClassFor($key))
            ->toBe($surface['routing_class'], "routing class for {$key} did not come from the artefact");
    }
})->skip(fn () => ! CompiledCatalog::isCompiled(), 'compiled catalog artefact missing');

it('answers null for a surface the catalog does not know', function () {
    expect(LegacyPlatformMap::routingClassFor('definitely.notasurface'))->toBeNull();
    expect(LegacyPlatformMap::isKnownSurface('definitely.notasurface'))->toBeFalse();
});

it('accepts brands added after P1, not just the 72 legacy surfaces', function () {
    // The deleted const covered only the legacy 72. Any brand added since (e.g.
    // Shopify) was already catalog-only, which is exactly why the "fallback for
    // a missing artefact" story never held: it protected 72 of 111 surfaces.
    $postP1 = collect(CompiledCatalog::surfaces())
        ->keys()
        ->first(fn (string $k) => str_starts_with($k, 'shopify.'));

    expect($postP1)->not->toBeNull('expected at least one shopify.* surface in the catalog');
    expect(LegacyPlatformMap::isKnownSurface($postP1))->toBeTrue();
})->skip(fn () => ! CompiledCatalog::isCompiled(), 'compiled catalog artefact missing');

it('keeps no hand-rolled surface-to-routing-class table outside App\\Catalog', function () {
    $classes = routingClassValues();
    sort($classes);

    // A map literal pairing a surface key with a routing class, e.g.
    //   'fresha.book' => 'booking',
    // Two or more such pairs in one file outside App\Catalog means a second
    // lane has grown back.
    $pattern = "/'[a-z0-9_]+\.[a-z0-9_]+'\s*=>\s*'(".implode('|', array_map('preg_quote', $classes)).")'/";

    $offenders = [];

    foreach (['app'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir)));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace(base_path().'/', '', $file->getPathname());

            // App\Catalog IS the one permitted home — the artefact and the
            // definitions that compile into it live here by design.
            if (str_starts_with($path, 'app/Catalog/')) {
                continue;
            }

            $hits = preg_match_all($pattern, (string) file_get_contents($file->getPathname()));

            if ($hits >= 2) {
                $offenders[$path] = $hits;
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'surface => routing-class table(s) found outside App\\Catalog: '.json_encode($offenders)
            ."\nRouting class comes from CompiledCatalog::surface(\$key)['routing_class'] — do not re-table it.",
    );
})->skip(fn () => ! CompiledCatalog::isCompiled(), 'compiled catalog artefact missing');
