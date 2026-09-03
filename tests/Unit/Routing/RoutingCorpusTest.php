<?php

use App\Catalog\CompiledCatalog;
use App\Routing\IriCanonicalizer;
use App\Routing\LinkProjector;
use App\Routing\PublicSuffixList;
use App\Routing\Rulepack;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// The P2 gate (plan §20): the whole corpus, DB-free, against the real
// canonicaliser + projector. The positive half is generated from the catalog
// so it can never fall behind it; the negative half is hand-written because
// attacker shapes cannot be derived from the rules they attack.

function corpusProjector(): LinkProjector
{
    static $projector = null;

    return $projector ??= new LinkProjector(Rulepack::fromCompiledCatalog());
}

function corpusCanonicalizer(): IriCanonicalizer
{
    static $canonicalizer = null;

    return $canonicalizer ??= new IriCanonicalizer(PublicSuffixList::instance());
}

it('places every generated positive on its own surface with its own identifier', function () {
    $cases = require base_path('tests/fixtures/Routing/corpus-generated.php');
    expect($cases)->not->toBeEmpty();

    $failures = [];
    foreach ($cases as $case) {
        $projection = corpusProjector()->project(corpusCanonicalizer()->canonicalize($case['url']));

        if (! $projection->matched()) {
            $failures[] = "{$case['url']} → no match ({$projection->reason}), expected {$case['expect']['surface']}";

            continue;
        }
        if ($projection->surfaceKey !== $case['expect']['surface']) {
            $failures[] = "{$case['url']} → {$projection->surfaceKey}, expected {$case['expect']['surface']}";

            continue;
        }
        $expectedId = $case['expect']['identifier'] ?? null;
        if ($expectedId !== null && $projection->identifier !== $expectedId) {
            $failures[] = "{$case['url']} → identifier '{$projection->identifier}', expected '{$expectedId}'";
        }
    }

    expect($failures)->toBe([], count($failures).' corpus positive(s) regressed:'.PHP_EOL.implode(PHP_EOL, $failures));
});

it('never places a surface for anything in the negative corpus', function () {
    $cases = require base_path('tests/fixtures/Routing/corpus-negatives.php');
    expect(count($cases))->toBeGreaterThan(150);

    $failures = [];
    foreach ($cases as $case) {
        $projection = corpusProjector()->project(corpusCanonicalizer()->canonicalize($case['url']));

        if ($projection->matched()) {
            $failures[] = sprintf(
                '%s → %s (id %s, detector %s) — expected NO match [%s]',
                $case['url'],
                $projection->surfaceKey,
                $projection->identifier ?? '-',
                $projection->detectorId ?? '-',
                $case['reason'],
            );
        }
    }

    expect($failures)->toBe([], count($failures).' URL(s) connected that must never connect:'.PHP_EOL.implode(PHP_EOL, $failures));
});

it('covers every placeable detector in the catalog', function () {
    $cases = require base_path('tests/fixtures/Routing/corpus-generated.php');
    $covered = array_unique(array_column($cases, 'detector'));

    $placeable = [];
    foreach (CompiledCatalog::detectors() as $id => $detector) {
        if ($detector['surface_key'] !== null) {
            $placeable[] = $id;
        }
    }

    $uncovered = array_values(array_diff($placeable, $covered));

    expect($uncovered)->toBe([], count($uncovered).' detector(s) have no corpus case — run `php artisan routing:corpus`:'.PHP_EOL.implode(PHP_EOL, $uncovered));
});

// #SEM-4 — not added to corpus-generated.php: that file is machine-generated
// ("do not edit by hand", enforced by the --check test below) from one case
// per catalog detector against bare hostnames, and www-prefixed variants
// aren't part of that generation. Drives the real projector pipeline
// directly instead, proving www.acme.myshopify.com now projects to the same
// Shopify surface + tenant identifier the bare form does.
it('#SEM-4: a www-prefixed tenant host projects to the Shopify surface with the tenant identifier', function () {
    $projection = corpusProjector()->project(corpusCanonicalizer()->canonicalize('https://www.acme.myshopify.com/products/thing'));

    expect($projection->matched())->toBeTrue()
        ->and($projection->surfaceKey)->toBe('shopify.store')
        ->and($projection->identifier)->toBe('acme');
});

it('keeps the generated corpus in step with the catalog', function () {
    // Guards the stale-fixture failure mode: definitions change, corpus does
    // not, and the suite keeps asserting yesterday's rules.
    Artisan::call('routing:corpus', ['--check' => true]);

    expect(Artisan::output())->not->toContain('stale');
});
