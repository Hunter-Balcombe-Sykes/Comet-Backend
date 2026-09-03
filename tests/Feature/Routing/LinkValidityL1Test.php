<?php

use App\Catalog\CompiledCatalog;
use App\Routing\IriCanonicalizer;
use App\Routing\LinkProjector;
use App\Routing\LinkValidity;
use App\Routing\Projection;
use App\Routing\Rulepack;

/**
 * B.4 — L1 is defined on the detector contract that already exists, NOT on a
 * parallel "host + path" grammar. The cases below are the ones that decide
 * whether the definition is the right one; each is a real URL shape.
 */
function projectFor(string $url): Projection
{
    $iri = app(IriCanonicalizer::class)->canonicalize($url);

    return (new LinkProjector(Rulepack::fromCompiledCatalog()))->project($iri);
}

it('passes an OpenTable ccTLD link whose identity comes from the QUERY, not a path', function () {
    // 32 of OpenTable's 48 detectors carry an empty path_pattern with
    // query_requires ['restRef']. A path-only definition of L1 would refuse
    // every one of them — this is the case that rules that definition out.
    $projection = projectFor('https://www.opentable.com.au/r/?restRef=291533');

    expect($projection->matched())->toBeTrue()
        ->and(LinkValidity::l1($projection))->toBe(LinkValidity::PASS);
});

it('marks a bare branded domain as WEAK — it identifies a brand, not an account', function () {
    $projection = projectFor('https://www.opentable.com.au/');

    // It still MATCHES (the host is unmistakably OpenTable). What it does not
    // do is name a restaurant, which is the whole of L1's question.
    expect(LinkValidity::l1($projection))->not->toBe(LinkValidity::PASS);
});

it('marks a marketing path on a connectable host as WEAK, not as an account', function () {
    $projection = projectFor('https://www.doordash.com/promo');

    expect(LinkValidity::l1($projection))->not->toBe(LinkValidity::PASS);
});

it('passes a link whose identifier the detector captured by name', function () {
    $projection = projectFor('https://www.instagram.com/partna.au/');

    expect($projection->identifier)->not->toBeNull()
        ->and(LinkValidity::l1($projection))->toBe(LinkValidity::PASS);
});

it('reports NONE — not WEAK — for a URL nothing matched', function () {
    $projection = projectFor('https://example-nothing-matches-this.test/x');

    expect(LinkValidity::l1($projection))->toBe(LinkValidity::NONE);
});

it('never judges an identity the person gave us rather than one read off a URL', function () {
    // Google Business (place_id) and the sign-up Instagram handle must not be
    // able to fail closed: there is no path for them to have failed.
    foreach (CompiledCatalog::surfaces() as $surface) {
        if (in_array($surface['identifier_kind'], ['place_id', 'domain', 'handle'], true)) {
            expect(LinkValidity::applies($surface))->toBeFalse();
        }
    }
})->group('catalog');

it('applies to a connectable, active, url-kind surface', function () {
    $surface = CompiledCatalog::surface('doordash.order');

    expect($surface)->not->toBeNull()
        ->and(LinkValidity::applies($surface))->toBeTrue();
});

it('does not apply to a retired surface — a brand that stopped trading is not an invalid link', function () {
    $surface = CompiledCatalog::surface('menulog.order');

    expect($surface['lifecycle'])->toBe('retired')
        ->and(LinkValidity::applies($surface))->toBeFalse();
});

/**
 * The census this definition was written against, pinned so the pattern fleet
 * (C.2) has a number to move and so a regression that silently re-broadens the
 * detectors is visible. RAISE the ceiling only with a reason; the direction of
 * travel is down.
 */
it('pins how many connectable surfaces still have no rule beyond their domain', function () {
    $detectors = CompiledCatalog::detectors();
    $weakSurfaces = [];

    foreach (CompiledCatalog::surfaces() as $surface) {
        if (! LinkValidity::applies($surface)) {
            continue;
        }

        $specific = 0;
        foreach ($surface['detectors'] as $id) {
            if (isset($detectors[$id]) && LinkValidity::detectorIsSpecific($detectors[$id])) {
                $specific++;
            }
        }

        if ($specific === 0) {
            $weakSurfaces[] = $surface['key'];
        }
    }

    // 2026-09-03 baseline: 56. Every one of these connects a link it cannot
    // name, refresh or verify — the Uber Eats "no restaurant name" card is one
    // of them, not a bug of its own.
    expect(count($weakSurfaces))->toBeLessThanOrEqual(56);
})->group('catalog');
