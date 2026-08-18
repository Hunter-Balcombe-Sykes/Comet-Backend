<?php

// tests/Feature/Platforms/CatalogClassificationSweepTest.php

use App\Catalog\CompiledCatalog;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\WebsiteLinkHarvester;
use Tests\Support\Catalog\SweepProbeUrl;

// B1 (spec 2026-08-18-pipeline-assurance §5): every catalog surface must be
// reachable through the real classifier. Cases are DERIVED from the compiled
// catalog at runtime — a new definition adds its own coverage; a hand list
// would rot exactly the way that produced N1 (39 invisible hosts).

function sweepSurfaces(): array
{
    expect(CompiledCatalog::isCompiled())->toBeTrue('bootstrap/catalog/compiled.php missing — run php artisan catalog:compile');
    $retired = array_flip(IntegrationConnection::RETIRED_SURFACES);

    return array_filter(CompiledCatalog::surfaces(), fn ($s, $k) => ! isset($retired[$k]), ARRAY_FILTER_USE_BOTH);
}

function sweepHandWritten(): array
{
    return require dirname(__DIR__, 2).'/fixtures/catalog/probe-urls.php';
}

function sweepKnownInvisible(): array
{
    return require dirname(__DIR__, 2).'/fixtures/catalog/known-invisible.php';
}

it('has a probe URL for every surface (template or hand-written)', function () {
    $missing = [];
    foreach (sweepSurfaces() as $key => $surface) {
        if (SweepProbeUrl::for($surface, sweepHandWritten()) === null) {
            $missing[] = $key;
        }
    }
    sort($missing);

    expect($missing)->toBeEmpty(
        "These surfaces declare no canonical_url_template and have no entry in tests/fixtures/catalog/probe-urls.php:\n - "
        .implode("\n - ", $missing),
    );
});

it('classifies every surface, or the surface is a pinned known gap', function () {
    $classifier = app(WebsiteLinkHarvester::class);
    $known = array_flip(sweepKnownInvisible());
    $newlyInvisible = [];
    $nowVisible = [];

    foreach (sweepSurfaces() as $key => $surface) {
        $url = SweepProbeUrl::for($surface, sweepHandWritten());
        if ($url === null) {
            continue; // reported by the test above
        }
        $bucket = SweepProbeUrl::bucket($classifier->classify($url));
        if ($bucket === 'invisible' && ! isset($known[$key])) {
            $newlyInvisible[] = "{$key}  ({$url})";
        }
        if ($bucket !== 'invisible' && isset($known[$key])) {
            $nowVisible[] = $key;
        }
    }

    expect($newlyInvisible)->toBeEmpty(
        "classify() returns null for these surfaces and they are NOT in known-invisible.php (regression, or a new gap to record in the report):\n - "
        .implode("\n - ", $newlyInvisible),
    );
    expect($nowVisible)->toBeEmpty(
        "These known-invisible.php rows now classify — remove them and note the improvement in the report:\n - "
        .implode("\n - ", $nowVisible),
    );
});

it('does not classify a retired surface into a real connection', function () {
    // Regression guard (spec 2026-08-18-pipeline-assurance §5 B5 point 4):
    // RETIRED_SURFACES must never appear in the swept set. sweepSurfaces()'s
    // array_filter() already enforces this — this pins that behaviour so the
    // filter itself cannot silently regress. Without this assertion the loop
    // below is permanently vacuous: none of the 6 retired surfaces carries a
    // canonical_url_template or a probe-urls.php entry, so classify() is
    // never reached for any of them and the test asserts nothing at all.
    // A separate expect() (not chained) so this assertion is independently
    // proven rather than riding on the loop below's pass/skip.
    $leaked = array_intersect(array_keys(sweepSurfaces()), IntegrationConnection::RETIRED_SURFACES);
    sort($leaked);

    expect($leaked)->toBeEmpty(
        "RETIRED_SURFACES leaked into the swept set (array_filter() in sweepSurfaces() regressed):\n - "
        .implode("\n - ", $leaked),
    );

    // Forward guard: kept from the original test. If a retired surface ever
    // DOES gain a probe URL (a template or a probe-urls.php entry), it must
    // never classify as a real connection. Currently a no-op for all 6 (none
    // has a probe URL) — stays wired for the day one does.
    $classifier = app(WebsiteLinkHarvester::class);
    foreach (IntegrationConnection::RETIRED_SURFACES as $key) {
        $surface = CompiledCatalog::surface($key);
        if ($surface === null) {
            continue; // not in the catalog at all — nothing to check
        }
        $url = SweepProbeUrl::for($surface, sweepHandWritten());
        if ($url === null) {
            continue;
        }
        expect(SweepProbeUrl::bucket($classifier->classify($url)))->not->toBe('connectable', "retired surface {$key} classified as connectable");
    }
});

// The report's headline numbers, printed once so the executor can paste them.
it('prints the bucket split', function () {
    $classifier = app(WebsiteLinkHarvester::class);
    $counts = ['connectable' => [], 'link-only' => [], 'invisible' => [], 'no-probe' => []];
    foreach (sweepSurfaces() as $key => $surface) {
        $url = SweepProbeUrl::for($surface, sweepHandWritten());
        if ($url === null) {
            $counts['no-probe'][] = $key;

            continue;
        }
        $counts[SweepProbeUrl::bucket($classifier->classify($url))][] = $key;
    }
    fwrite(STDERR, "\nCATALOG SWEEP: ".json_encode(array_map('count', $counts))."\n".json_encode($counts, JSON_PRETTY_PRINT)."\n");
    expect(true)->toBeTrue();
})->skip(getenv('CATALOG_SWEEP_REPORT') !== '1', 'set CATALOG_SWEEP_REPORT=1 to print');
