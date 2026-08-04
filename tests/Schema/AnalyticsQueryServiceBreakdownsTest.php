<?php

// tests/Schema/AnalyticsQueryServiceBreakdownsTest.php (moved from tests/Unit/Analytics/)
//
// #TEST-1 sub-item 3 — seeded correctness for the CASE-heavy breakdown methods.
//
// #COV-LANE-4 drift bug: analytics.site_visits.site_id is NOT NULL with a real
// FK onto site.sites (site_visits_site_fk, ON DELETE CASCADE — baseline_pilot.sql).
// The old SQLite fixture's insertSiteVisitRow() never set site_id at all; SQLite's
// test-schema stand-in for the table has no NOT NULL enforcement on it, so five
// tests passed there while seeding rows real Postgres would refuse outright.
// This is a FIXTURE fault, not a production one: PostgresEventWriter::visitRow()
// (the only real write path into this table) always sets 'site_id' => $e->siteId
// unconditionally, and AnalyticsEvent's $siteId is a required constructor
// argument — the app never omits it. The fixture just forgot to.
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
//
// Now that the file lives in the applied-schema lane, every case above runs for
// real against Postgres — the referrers() skip is left in place only as a
// defensive no-op (SchemaTestCase already guarantees a real pgsql connection),
// documenting why the case exists rather than gating anything live.

use App\Models\Core\Site\Site;
use App\Services\Analytics\AnalyticsQueryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Schema\Concerns\SeedsAuthUsers;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class, SeedsAuthUsers::class)->in(__FILE__);

beforeEach(function () {
    $this->seededUser = $this->seedAuthUser();
    $this->userId = $this->seededUser->id;

    $this->site = Site::factory()->for($this->seededUser, 'user')->create();
    $this->siteId = $this->site->id;

    $this->from = Carbon::now()->subDays(7);
    $this->to = Carbon::now();
    $this->service = app(AnalyticsQueryService::class);
});

afterEach(function () {
    // No RefreshDatabase in this lane — the DB is persistent and shared across
    // the whole run. forceDelete cascades core.users -> site.sites (CASCADE)
    // -> analytics.site_visits (CASCADE), so this one call tears down every
    // row seeded above.
    $this->cleanupSeededUser($this->seededUser);
});

/**
 * Inserts a single analytics.site_visits row with sane defaults, overridable
 * per test. Mirrors the pattern established by TopItemsBySectionTest's
 * insertLinkClick() for the sibling table. site_id is a required, explicit
 * argument (not defaulted/omitted) precisely because the NOT NULL + FK the
 * SQLite fixture used to skip is the drift bug this move fixes.
 */
function insertSiteVisitRow(string $userId, string $siteId, array $overrides = []): void
{
    DB::connection('pgsql')->table('analytics.site_visits')->insert(array_merge([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'site_id' => $siteId,
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
    insertSiteVisitRow($this->userId, $this->siteId, ['device_type' => 'desktop']);
    insertSiteVisitRow($this->userId, $this->siteId, ['device_type' => 'desktop']);
    insertSiteVisitRow($this->userId, $this->siteId, ['device_type' => 'mobile']);
    insertSiteVisitRow($this->userId, $this->siteId, ['device_type' => 'mobile']);
    insertSiteVisitRow($this->userId, $this->siteId, ['device_type' => 'mobile']);
    insertSiteVisitRow($this->userId, $this->siteId, ['device_type' => 'tablet']);
    insertSiteVisitRow($this->userId, $this->siteId, ['device_type' => null]);
    insertSiteVisitRow($this->userId, $this->siteId, ['device_type' => 'smart-fridge']);

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
    insertSiteVisitRow($this->userId, $this->siteId, ['device_type' => 'desktop', 'occurred_at' => now()->subDays(30)->toDateTimeString()]);
    insertSiteVisitRow($this->userId, $this->siteId, ['device_type' => 'desktop']);

    $result = $this->service->deviceTotals($this->userId, $this->from, $this->to);

    expect($result)->toBe(['desktop' => 1, 'mobile' => 0, 'other' => 0]);
});

// ─── countries() — runs for real on SQLite ─────────────────────────────────

it('countries() returns the top 4 country codes by unique visitors plus an OTHER bucket for the rest', function () {
    // Distinct counts per country (no ties) so the top-4 cutoff is deterministic.
    foreach (['AU', 'AU', 'AU', 'AU', 'AU'] as $code) {
        insertSiteVisitRow($this->userId, $this->siteId, ['country_code' => $code]);
    }
    foreach (['US', 'US', 'US', 'US'] as $code) {
        insertSiteVisitRow($this->userId, $this->siteId, ['country_code' => $code]);
    }
    foreach (['NZ', 'NZ', 'NZ'] as $code) {
        insertSiteVisitRow($this->userId, $this->siteId, ['country_code' => $code]);
    }
    foreach (['GB', 'GB'] as $code) {
        insertSiteVisitRow($this->userId, $this->siteId, ['country_code' => $code]);
    }
    insertSiteVisitRow($this->userId, $this->siteId, ['country_code' => 'FR']);

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
    insertSiteVisitRow($this->userId, $this->siteId, ['country_code' => null]);
    insertSiteVisitRow($this->userId, $this->siteId, ['country_code' => 'AU']);

    $result = $this->service->countries($this->userId, $this->from, $this->to);
    $byCode = collect($result)->keyBy('country_code');

    expect($result)->toHaveCount(2)
        ->and($byCode['UN']['visitors'])->toBe(1)
        ->and($byCode['AU']['visitors'])->toBe(1);
});

it('countries() counts unique visitors, not raw row count', function () {
    $sharedVisitor = (string) Str::uuid();
    insertSiteVisitRow($this->userId, $this->siteId, ['country_code' => 'AU', 'visitor_id' => $sharedVisitor]);
    insertSiteVisitRow($this->userId, $this->siteId, ['country_code' => 'AU', 'visitor_id' => $sharedVisitor]);
    insertSiteVisitRow($this->userId, $this->siteId, ['country_code' => 'AU']);

    $result = $this->service->countries($this->userId, $this->from, $this->to);

    expect($result)->toBe([['country_code' => 'AU', 'visitors' => 2]]);
});

// ─── referrers() — Postgres-only (SOURCE_CASE uses ILIKE) ──────────────────

it('referrers() maps referrer/utm_source values to labelled, zero-filled buckets', function () {
    insertSiteVisitRow($this->userId, $this->siteId, ['referrer' => 'https://l.instagram.com/foo', 'utm_source' => null]);
    insertSiteVisitRow($this->userId, $this->siteId, ['referrer' => null, 'utm_source' => null]);
    insertSiteVisitRow($this->userId, $this->siteId, ['referrer' => 'https://some-random-blog.example', 'utm_source' => null]);
    insertSiteVisitRow($this->userId, $this->siteId, ['referrer' => null, 'utm_source' => 'facebook_ads']);

    $result = $this->service->referrers($this->userId, $this->from, $this->to);
    $byLabel = collect($result)->keyBy('label');

    // Fixed-order output: every REFERRER_LABELS entry is present even with 0
    // visitors. 14, not 15 — AnalyticsQueryService::REFERRER_LABELS is 12
    // REFERRER_SOURCES keys + the 2 structural labels (Direct Link, Other),
    // confirmed by the #FOUND-3 reflection guard in
    // AnalyticsQueryServiceConfigDrivenTest. This assertion was never executed
    // before (gated behind a real pgsql driver, which the old tests/Unit/
    // SQLite lane never had) — 15 was simply a miscount that nothing had ever
    // run against real data to catch.
    expect($result)->toHaveCount(14)
        ->and($byLabel['Instagram']['visitors'])->toBe(1)
        ->and($byLabel['Direct Link']['visitors'])->toBe(1)
        ->and($byLabel['Other']['visitors'])->toBe(1)
        ->and($byLabel['Facebook']['visitors'])->toBe(1)
        ->and($byLabel['TikTok']['visitors'])->toBe(0);
});
