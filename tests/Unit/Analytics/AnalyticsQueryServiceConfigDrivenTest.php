<?php

/**
 * Pure-logic guards for #FOUND-2 (section titles moved to config) and #FOUND-3
 * (referrer CASE + labels single-sourced from REFERRER_SOURCES). No DB — the
 * real referrers()/topSections() queries are Postgres-only (ILIKE/DATE/DISTINCT
 * ::text) and don't run against the SQLite test DB, so this file exercises the
 * title/label logic directly via reflection instead of executing SQL.
 */

use App\Services\Analytics\AnalyticsQueryService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

// sectionTitle() reads config('partna.analytics.section_titles'), which needs
// the Laravel app container booted — opt into the full TestCase.
uses(TestCase::class)->in(__FILE__);

/**
 * Invokes the private instance method sectionTitle() via reflection.
 */
function invokeSectionTitle(string $sectionKey): string
{
    $service = (new ReflectionClass(AnalyticsQueryService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($service, 'sectionTitle');

    return $method->invoke($service, $sectionKey);
}

/**
 * Invokes the private static method sourceCase() via reflection.
 */
function invokeSourceCase(): string
{
    $method = new ReflectionMethod(AnalyticsQueryService::class, 'sourceCase');

    return $method->invoke(null);
}

// #FOUND-2 — sectionTitle() reads config('partna.analytics.section_titles')

it('resolves a skeleton section key from config', function () {
    expect(invokeSectionTitle('shop'))->toBe('Shop');
});

it('resolves a legacy block-era section key from config', function () {
    expect(invokeSectionTitle('gallery'))->toBe('Gallery of Work');
});

it('humanizes an unknown section key not present in config', function () {
    expect(invokeSectionTitle('courses'))->toBe('Courses');
});

it('reads the map from config, not a hardcoded copy — override proves it', function () {
    Config::set('partna.analytics.section_titles', ['shop' => 'Overridden Label']);

    expect(invokeSectionTitle('shop'))->toBe('Overridden Label');
});

// #FOUND-3 — REFERRER_SOURCES / REFERRER_LABELS / sourceCase() can't silently diverge

it('REFERRER_LABELS is exactly a permutation of REFERRER_SOURCES keys plus the structural labels', function () {
    $sources = (new ReflectionClass(AnalyticsQueryService::class))->getConstant('REFERRER_SOURCES');
    $labels = (new ReflectionClass(AnalyticsQueryService::class))->getConstant('REFERRER_LABELS');

    $expected = [...array_keys($sources), 'Direct Link', 'Other'];

    // Same multiset of labels regardless of order (REFERRER_LABELS is display
    // order; REFERRER_SOURCES is evaluation order — they intentionally differ).
    sort($expected);
    $actual = $labels;
    sort($actual);

    expect($actual)->toBe($expected);
});

it('sourceCase() emits a THEN clause for every REFERRER_SOURCES label plus the structural clauses, in evaluation order', function () {
    $sources = (new ReflectionClass(AnalyticsQueryService::class))->getConstant('REFERRER_SOURCES');
    $case = invokeSourceCase();

    expect($case)->toStartWith('CASE');
    expect(rtrim($case))->toEndWith("ELSE 'Other'\nEND");
    expect($case)->toContain("WHEN referrer IS NULL OR referrer = '' THEN 'Direct Link'");

    // Every source label appears as a THEN target, and in the same relative
    // order as REFERRER_SOURCES — a spelling drift or reorder would break this.
    $lastPos = -1;
    foreach ($sources as $label => $predicate) {
        $needle = "THEN '{$label}'";
        $pos = strpos($case, $needle);
        expect($pos)->not->toBeFalse("Missing THEN clause for label: {$label}");
        expect($pos)->toBeGreaterThan($lastPos, "Label out of evaluation order: {$label}");
        $lastPos = $pos;

        // The predicate itself must also be present verbatim ahead of its THEN.
        expect($case)->toContain($predicate);
    }
});

it('sourceCase() contains exactly one WHEN per source label plus the two structural WHENs', function () {
    $sources = (new ReflectionClass(AnalyticsQueryService::class))->getConstant('REFERRER_SOURCES');
    $case = invokeSourceCase();

    $whenCount = substr_count($case, 'WHEN ');
    expect($whenCount)->toBe(count($sources) + 1); // +1 for the Direct Link structural WHEN ('Other' is ELSE, not WHEN)
});
