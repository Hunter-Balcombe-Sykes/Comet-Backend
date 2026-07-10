<?php

// tests/Unit/Analytics/AnalyticsQueryServiceBreakdownsTest.php
//
// #TEST-1 sub-item 3 — seeded correctness for the CASE-heavy breakdown methods.
//
// What genuinely EXECUTES on SQLite vs what's Postgres-only, and why:
//   - deviceTotals(): plain CASE/WHEN over device_type, COUNT(*) — no ILIKE, no
//     FILTER, no ::text cast. Runs for real on SQLite.
//   - countries(): COALESCE + groupByRaw + the driver-branched uniqueVisitorExpr()
//     (AnalyticsQueryService::uniqueVisitorExpr() drops the ::text cast on SQLite —
//     see that method's docblock). Runs for real on SQLite.
//   - referrers(): builds its CASE from sourceCase(), which is built entirely out
//     of ILIKE predicates (REFERRER_SOURCES). SQLite has no ILIKE operator at all —
//     empirically this throws "near "ILIKE": syntax error", not a silent
//     case-sensitivity difference. So referrers() is marked Postgres-only and
//     skipped here rather than faked green (AnalyticsQueryServiceConfigDrivenTest
//     already covers the CASE-construction logic itself via reflection, without
//     executing SQL).

use App\Services\Analytics\AnalyticsQueryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupSiteVisitsTable();

    $this->userId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $this->userId,
        'display_name' => 'Breakdown Test Pro',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $this->from = Carbon::now()->subDays(7);
    $this->to = Carbon::now();
    $this->service = app(AnalyticsQueryService::class);
});

/**
 * Inserts a single analytics.site_visits row with sane defaults, overridable
 * per test. Mirrors the pattern established by TopItemsBySectionTest's
 * insertLinkClick() for the sibling table.
 */
function insertSiteVisitRow(string $userId, array $overrides = []): void
{
    DB::connection('pgsql')->table('analytics.site_visits')->insert(array_merge([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'occurred_at' => now()->subHours(1)->toDateTimeString(),
        'visitor_id' => (string) Str::uuid(),
        'device_type' => null,
        'country_code' => null,
        'referrer' => null,
        'utm_source' => null,
        'created_at' => now()->toDateTimeString(),
    ], $overrides));
}

// ─── deviceTotals() — runs for real on SQLite ──────────────────────────────

it('deviceTotals() buckets desktop/mobile/tablet/unknown device_type values correctly', function () {
    insertSiteVisitRow($this->userId, ['device_type' => 'desktop']);
    insertSiteVisitRow($this->userId, ['device_type' => 'desktop']);
    insertSiteVisitRow($this->userId, ['device_type' => 'mobile']);
    insertSiteVisitRow($this->userId, ['device_type' => 'mobile']);
    insertSiteVisitRow($this->userId, ['device_type' => 'mobile']);
    insertSiteVisitRow($this->userId, ['device_type' => 'tablet']);
    insertSiteVisitRow($this->userId, ['device_type' => null]);
    insertSiteVisitRow($this->userId, ['device_type' => 'smart-fridge']);

    $result = $this->service->deviceTotals($this->userId, $this->from, $this->to);

    expect($result)->toBe([
        'desktop' => 2,
        // tablet collapses into the mobile bucket alongside mobile itself (3+1).
        'mobile' => 4,
        // null and any unrecognized device_type both fall through to ELSE.
        'other' => 2,
    ]);
});

it('deviceTotals() only counts visits inside the requested window', function () {
    insertSiteVisitRow($this->userId, ['device_type' => 'desktop', 'occurred_at' => now()->subDays(30)->toDateTimeString()]);
    insertSiteVisitRow($this->userId, ['device_type' => 'desktop']);

    $result = $this->service->deviceTotals($this->userId, $this->from, $this->to);

    expect($result)->toBe(['desktop' => 1, 'mobile' => 0, 'other' => 0]);
});

// ─── countries() — runs for real on SQLite ─────────────────────────────────

it('countries() returns the top 4 country codes by unique visitors plus an OTHER bucket for the rest', function () {
    // Distinct counts per country (no ties) so the top-4 cutoff is deterministic.
    foreach (['AU', 'AU', 'AU', 'AU', 'AU'] as $code) {
        insertSiteVisitRow($this->userId, ['country_code' => $code]);
    }
    foreach (['US', 'US', 'US', 'US'] as $code) {
        insertSiteVisitRow($this->userId, ['country_code' => $code]);
    }
    foreach (['NZ', 'NZ', 'NZ'] as $code) {
        insertSiteVisitRow($this->userId, ['country_code' => $code]);
    }
    foreach (['GB', 'GB'] as $code) {
        insertSiteVisitRow($this->userId, ['country_code' => $code]);
    }
    insertSiteVisitRow($this->userId, ['country_code' => 'FR']);

    $result = $this->service->countries($this->userId, $this->from, $this->to);
    $byCode = collect($result)->keyBy('country_code');

    expect($result)->toHaveCount(5)
        ->and($byCode['AU']['visitors'])->toBe(5)
        ->and($byCode['US']['visitors'])->toBe(4)
        ->and($byCode['NZ']['visitors'])->toBe(3)
        ->and($byCode['GB']['visitors'])->toBe(2)
        // FR is the 5th distinct group — bumped out of the top 4 into OTHER.
        ->and($byCode['OTHER']['visitors'])->toBe(1);
});

it('countries() buckets a null country_code under UN', function () {
    insertSiteVisitRow($this->userId, ['country_code' => null]);
    insertSiteVisitRow($this->userId, ['country_code' => 'AU']);

    $result = $this->service->countries($this->userId, $this->from, $this->to);
    $byCode = collect($result)->keyBy('country_code');

    expect($result)->toHaveCount(2)
        ->and($byCode['UN']['visitors'])->toBe(1)
        ->and($byCode['AU']['visitors'])->toBe(1);
});

it('countries() counts unique visitors, not raw row count', function () {
    $sharedVisitor = (string) Str::uuid();
    insertSiteVisitRow($this->userId, ['country_code' => 'AU', 'visitor_id' => $sharedVisitor]);
    insertSiteVisitRow($this->userId, ['country_code' => 'AU', 'visitor_id' => $sharedVisitor]);
    insertSiteVisitRow($this->userId, ['country_code' => 'AU']);

    $result = $this->service->countries($this->userId, $this->from, $this->to);

    expect($result)->toBe([['country_code' => 'AU', 'visitors' => 2]]);
});

// ─── referrers() — Postgres-only (SOURCE_CASE uses ILIKE) ──────────────────

it('referrers() maps referrer/utm_source values to labelled, zero-filled buckets', function () {
    if (DB::connection('pgsql')->getDriverName() !== 'pgsql') {
        $this->markTestSkipped(
            'referrers() builds its CASE from SOURCE_CASE, which is entirely ILIKE '.
            'predicates. SQLite has no ILIKE operator (proven empirically: "SELECT ... '.
            'ILIKE ..." throws "near \"ILIKE\": syntax error" against SQLite, it is not a '.
            'silent case-sensitivity difference) — this method only runs against real '.
            'Postgres. AnalyticsQueryServiceConfigDrivenTest covers the CASE-construction '.
            'logic itself (REFERRER_SOURCES/REFERRER_LABELS/sourceCase()) without SQL.'
        );
    }

    insertSiteVisitRow($this->userId, ['referrer' => 'https://l.instagram.com/foo', 'utm_source' => null]);
    insertSiteVisitRow($this->userId, ['referrer' => null, 'utm_source' => null]);
    insertSiteVisitRow($this->userId, ['referrer' => 'https://some-random-blog.example', 'utm_source' => null]);
    insertSiteVisitRow($this->userId, ['referrer' => null, 'utm_source' => 'facebook_ads']);

    $result = $this->service->referrers($this->userId, $this->from, $this->to);
    $byLabel = collect($result)->keyBy('label');

    // Fixed-order output: every REFERRER_LABELS entry is present even with 0 visitors.
    expect($result)->toHaveCount(15)
        ->and($byLabel['Instagram']['visitors'])->toBe(1)
        ->and($byLabel['Direct Link']['visitors'])->toBe(1)
        ->and($byLabel['Other']['visitors'])->toBe(1)
        ->and($byLabel['Facebook']['visitors'])->toBe(1)
        ->and($byLabel['TikTok']['visitors'])->toBe(0);
});
