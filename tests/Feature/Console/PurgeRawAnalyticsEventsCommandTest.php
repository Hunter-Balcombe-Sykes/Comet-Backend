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
