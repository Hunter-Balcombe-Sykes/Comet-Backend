<?php

// #CFG-1 — BATCH_SIZE moved from a hardcoded const to config('partna.analytics.purge_batch_size').
// #SLOP-1 — the command's docblock/$description no longer claim a (nonexistent) hourly/daily
// aggregate is preserved; site_metrics_daily/_hourly have no reader/writer.

use App\Console\Commands\PurgeRawAnalyticsEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    attachTestSchemas();
    setupSiteVisitsTable();
    setupLinkClicksTable();
    setupSiteSessionsTable();
    setupSectionViewsTable();
    setupItemViewsTable();

    // No shared Pest.php helper for this table — mirrors the minimal DDL used by
    // tests/Feature/Middleware/LogLeadRateLimitsTest.php.
    DB::connection('pgsql')->statement('DROP TABLE IF EXISTS analytics.lead_submissions');
    DB::connection('pgsql')->statement('CREATE TABLE analytics.lead_submissions (
        id TEXT PRIMARY KEY,
        occurred_at TEXT NULL
    )');
});

function seedOldSiteVisit(): void
{
    DB::connection('pgsql')->table('analytics.site_visits')->insert([
        'id' => (string) Str::uuid(),
        'occurred_at' => now()->subDays(100)->toDateTimeString(),
        'created_at' => now()->subDays(100)->toDateTimeString(),
    ]);
}

it('defaults the purge batch size to the pre-CFG-1 hardcoded value', function () {
    expect(config('partna.analytics.purge_batch_size'))->toBe(10_000);
});

it('purges every eligible row across multiple batches when config shrinks the batch size below the eligible set', function () {
    config(['partna.analytics.purge_batch_size' => 2]);

    for ($i = 0; $i < 5; $i++) {
        seedOldSiteVisit();
    }

    $this->artisan('partna:analytics:purge-raw-events')->assertExitCode(0);

    // All 5 rows gone even though the batch size (2) is smaller than the eligible set —
    // proves the do/while batching loop still terminates correctly off the config value.
    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(0);
});

it('dry-run leaves rows untouched regardless of the configured batch size', function () {
    config(['partna.analytics.purge_batch_size' => 1]);
    seedOldSiteVisit();

    $this->artisan('partna:analytics:purge-raw-events', ['--dry-run' => true])->assertExitCode(0);

    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(1);
});

it('no longer claims aggregate data is preserved in the command description', function () {
    $description = (new ReflectionClass(PurgeRawAnalyticsEvents::class))
        ->getDefaultProperties()['description'];

    expect($description)->not->toContain('preserved')
        ->and($description)->not->toContain('Aggregate');
});

// #TEST-1 sub-item 2 — every raw table PurgeRawAnalyticsEvents::TABLES lists, seeded
// with one row older than the default 90-day retention and one row inside it. Proves
// the purge is scoped per-table by the RIGHT timestamp column (site_sessions ages on
// last_seen_at, the other five on occurred_at — see the class const) and never
// touches a fresh row while clearing every stale one.
it('purges only rows older than the retention cutoff, independently, across all six raw analytics tables', function () {
    $old = now()->subDays(100)->toDateTimeString();
    $fresh = now()->subDays(1)->toDateTimeString();

    DB::connection('pgsql')->table('analytics.link_clicks')->insert([
        ['id' => (string) Str::uuid(), 'occurred_at' => $old],
        ['id' => (string) Str::uuid(), 'occurred_at' => $fresh],
    ]);
    DB::connection('pgsql')->table('analytics.site_visits')->insert([
        ['id' => (string) Str::uuid(), 'occurred_at' => $old, 'created_at' => $old],
        ['id' => (string) Str::uuid(), 'occurred_at' => $fresh, 'created_at' => $fresh],
    ]);
    DB::connection('pgsql')->table('analytics.lead_submissions')->insert([
        ['id' => (string) Str::uuid(), 'occurred_at' => $old],
        ['id' => (string) Str::uuid(), 'occurred_at' => $fresh],
    ]);
    DB::connection('pgsql')->table('analytics.section_views')->insert([
        ['id' => (string) Str::uuid(), 'section_key' => 'shop', 'occurred_at' => $old],
        ['id' => (string) Str::uuid(), 'section_key' => 'shop', 'occurred_at' => $fresh],
    ]);
    DB::connection('pgsql')->table('analytics.item_views')->insert([
        ['id' => (string) Str::uuid(), 'site_id' => (string) Str::uuid(), 'item_type' => 'product', 'item_id' => 'p1', 'occurred_at' => $old],
        ['id' => (string) Str::uuid(), 'site_id' => (string) Str::uuid(), 'item_type' => 'product', 'item_id' => 'p2', 'occurred_at' => $fresh],
    ]);
    // site_sessions ages on last_seen_at, not occurred_at — this is the one table
    // in the loop with a different timestamp column (PurgeRawAnalyticsEvents::TABLES).
    DB::connection('pgsql')->table('analytics.site_sessions')->insert([
        ['id' => (string) Str::uuid(), 'site_id' => (string) Str::uuid(), 'last_seen_at' => $old],
        ['id' => (string) Str::uuid(), 'site_id' => (string) Str::uuid(), 'last_seen_at' => $fresh],
    ]);

    $this->artisan('partna:analytics:purge-raw-events')->assertExitCode(0);

    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(1)
        ->and(DB::connection('pgsql')->table('analytics.link_clicks')->value('occurred_at'))->toBe($fresh)
        ->and(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(1)
        ->and(DB::connection('pgsql')->table('analytics.site_visits')->value('occurred_at'))->toBe($fresh)
        ->and(DB::connection('pgsql')->table('analytics.lead_submissions')->count())->toBe(1)
        ->and(DB::connection('pgsql')->table('analytics.lead_submissions')->value('occurred_at'))->toBe($fresh)
        ->and(DB::connection('pgsql')->table('analytics.section_views')->count())->toBe(1)
        ->and(DB::connection('pgsql')->table('analytics.section_views')->value('occurred_at'))->toBe($fresh)
        ->and(DB::connection('pgsql')->table('analytics.item_views')->count())->toBe(1)
        ->and(DB::connection('pgsql')->table('analytics.item_views')->value('occurred_at'))->toBe($fresh)
        ->and(DB::connection('pgsql')->table('analytics.site_sessions')->count())->toBe(1)
        ->and(DB::connection('pgsql')->table('analytics.site_sessions')->value('last_seen_at'))->toBe($fresh);
});

// #TEST-1 sub-item 2 — the --days guard's 30-day floor (retentionDays()).
it('rejects a --days value below the 30-day retention floor and exits with a failure code', function () {
    seedOldSiteVisit();

    $this->artisan('partna:analytics:purge-raw-events', ['--days' => 29])
        ->expectsOutputToContain('Retention window must be at least 30 days (got 29)')
        ->assertExitCode(1);

    // The guard rejects BEFORE any table is touched.
    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(1);
});

// #TEST-1 sub-item 2 — --dry-run must report the eligible count, not just "did nothing".
// The two expectsOutputToContain() assertions target two DIFFERENT printed lines
// (the per-table row and the final summary) — asserting two substrings that both
// happen to land on the SAME line would only satisfy one of them, since Mockery
// matches a single output write to a single expectation.
it('dry-run reports the eligible row count per table without deleting', function () {
    seedOldSiteVisit();
    seedOldSiteVisit();

    $this->artisan('partna:analytics:purge-raw-events', ['--dry-run' => true])
        ->expectsOutputToContain('analytics.site_visits')
        ->expectsOutputToContain('Would delete 2 total rows.')
        ->assertExitCode(0);

    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(2);
});
