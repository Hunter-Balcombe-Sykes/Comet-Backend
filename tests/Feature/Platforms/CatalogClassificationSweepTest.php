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
function sweepKnownLinkOnly(): array
{
    return require dirname(__DIR__, 2).'/fixtures/catalog/known-link-only.php';
}

// The seam guard (spec 2026-08-28-link-classifier-seam-design §B.3). classify()
// answers 'link' for a catalog surface no host constant covers and no promotable
// routing class claims: recognised, costs no probe, never auto-connected. That is
// a POLICY — but until this test it was invisible at add-time, so wave 2 could
// land 37 definitions with 3 harvester rows and nobody knew until a live find.
//
// Two-way, exactly like known-invisible.php above: a NEW detect-only surface
// fails (add the harvester row, or record the decision), and a stale row fails
// (it was promoted — delete the row).
it('records every detect-only surface as a decision, not an accident', function () {
    $classifier = app(WebsiteLinkHarvester::class);
    $known = array_flip(sweepKnownLinkOnly());
    $newlyLinkOnly = [];
    $noLongerLinkOnly = [];

    foreach (sweepSurfaces() as $key => $surface) {
        $url = SweepProbeUrl::for($surface, sweepHandWritten());
        if ($url === null) {
            continue; // reported by the probe-URL test above
        }
        $isLinkOnly = SweepProbeUrl::bucket($classifier->classify($url)) === 'link-only';
        if ($isLinkOnly && ! isset($known[$key])) {
            $newlyLinkOnly[] = "{$key}  ({$url})";
        }
        if (! $isLinkOnly && isset($known[$key])) {
            $noLongerLinkOnly[] = $key;
        }
    }
    sort($newlyLinkOnly);
    sort($noLongerLinkOnly);

    expect($newlyLinkOnly)->toBeEmpty(
        "These surfaces classify as a generic link card. Either give the brand a row in\n"
        ."WebsiteLinkHarvester's host constants, or record the detect-only decision (with a\n"
        ."reason) in tests/fixtures/catalog/known-link-only.php:\n - "
        .implode("\n - ", $newlyLinkOnly),
    );
    expect($noLongerLinkOnly)->toBeEmpty(
        "These known-link-only.php rows now classify into a real category — remove them:\n - "
        .implode("\n - ", $noLongerLinkOnly),
    );
});

// ── The two-entry-point agreement guard (spec §6.1 follow-up, 2026-08-30) ────
//
// classify() and harvestHtml() are this class's two entry points and they
// disagreed on exactly one lane: harvestHtml() walked the four host constants
// and STOPPED, never reaching classifyFromCatalog(). A catalog-only
// booking/ordering brand on a scraped homepage was therefore not mis-bucketed —
// it was absent from the payload entirely (measured 2026-08-30: shortcuts.book
// and easi.order, plus every future brand in those three classes).
//
// The invariant is agreement, not coverage: whatever classify() answers for a
// URL, harvestHtml() must put that URL in the matching bucket — and in NO
// bucket when classify() answers anything else. Stated that way it holds for
// the hand-written constants too, which legitimately bucket a few brands the
// catalog marks is_connectable=false; those constants answer first in BOTH
// entry points, so the two still agree.
//
// Derived from the compiled catalog, never hand-listed — a hand list is what
// let 39 hosts go invisible in the first place (N1), and the same rot is what
// produced this bug.
function harvestBuckets(): array
{
    // classify() category => the harvestHtml() payload key it must land in.
    // Exactly PROMOTABLE_ROUTING_CLASS's three categories: the ones whose
    // vocabulary is 1:1 with a real gate arm and a real seeder. Widening this
    // map without widening that const would re-open the disagreement.
    return ['booking' => 'booking', 'reservations' => 'reservation', 'online-ordering' => 'order'];
}

it('buckets a surface through harvestHtml() exactly as classify() categorises it', function () {
    $harvester = app(WebsiteLinkHarvester::class);
    $disagreements = [];

    foreach (sweepSurfaces() as $key => $surface) {
        $url = SweepProbeUrl::for($surface, sweepHandWritten());
        if ($url === null) {
            continue; // reported by the probe-URL test above
        }

        $category = $harvester->classify($url)['category'] ?? null;
        $out = $harvester->harvestHtml('<a href="'.htmlspecialchars($url, ENT_QUOTES).'">x</a>', 'https://harvest.test/');

        foreach (harvestBuckets() as $classifyCategory => $bucket) {
            $wanted = $category === $classifyCategory;
            if ($wanted !== isset($out[$bucket])) {
                $disagreements[] = $wanted
                    ? "{$key}  classify()='{$classifyCategory}' but harvestHtml() dropped it  ({$url})"
                    : "{$key}  classify()='".($category ?? 'null')."' yet harvestHtml() put it in '{$bucket}'  ({$url})";
            }
        }
    }
    sort($disagreements);

    expect($disagreements)->toBeEmpty(
        "The two entry points disagree. A dropped link means a scraped homepage loses it\n"
        ."entirely; an extra one means seedBooking()/seedOnlineOrdering() gets a write\n"
        ."classify() never sanctioned. Fix harvestHtml()'s fall-through, not this list:\n - "
        .implode("\n - ", $disagreements),
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
