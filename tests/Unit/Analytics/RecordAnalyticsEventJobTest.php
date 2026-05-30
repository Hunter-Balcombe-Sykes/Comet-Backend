<?php

// tests/Unit/Analytics/RecordAnalyticsEventJobTest.php

use App\Jobs\Analytics\RecordAnalyticsEventJob;
use App\Services\Analytics\AnalyticsCacheService;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Writers\PostgresEventWriter;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    setupSiteVisitsTable();
});

function pageviewPayload(string $userId, string $siteId): array
{
    return [
        'id' => (string) Str::orderedUuid(),
        'type' => AnalyticsEvent::TYPE_PAGEVIEW,
        'occurred_at' => now()->toISOString(),
        'user_id' => $userId, 'site_id' => $siteId,
    ];
}

it('persists the event and bumps the analytics summary version', function () {
    $t = createBrandTenant('job-happy');
    $payload = pageviewPayload($t->id, $t->site->id);

    (new RecordAnalyticsEventJob($payload))->handle(new PostgresEventWriter, app(AnalyticsCacheService::class));

    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(1)
        ->and(Cache::get(CacheKeyGenerator::analyticsSummaryVersion($t->id)))->not->toBeNull();
});

it('is idempotent across an at-least-once retry (handle twice → one row)', function () {
    $t = createBrandTenant('job-retry');
    $payload = pageviewPayload($t->id, $t->site->id);
    $writer = new PostgresEventWriter;
    $cache = app(AnalyticsCacheService::class);

    (new RecordAnalyticsEventJob($payload))->handle($writer, $cache);
    (new RecordAnalyticsEventJob($payload))->handle($writer, $cache);

    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(1);
});

it('targets the analytics queue', function () {
    expect((new RecordAnalyticsEventJob(['id' => 'x']))->queue)->toBe('analytics');
});
