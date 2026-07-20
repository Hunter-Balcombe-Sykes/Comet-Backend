<?php

// tests/Unit/Analytics/RecordAnalyticsEventJobTest.php

use App\Jobs\Analytics\RecordAnalyticsEventJob;
use App\Services\Analytics\AnalyticsCacheService;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Writers\PostgresEventWriter;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
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
    $t = createTenant('job-happy');
    $payload = pageviewPayload($t->id, $t->site->id);

    (new RecordAnalyticsEventJob($payload))->handle(new PostgresEventWriter, app(AnalyticsCacheService::class));

    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(1)
        ->and(Cache::get(CacheKeyGenerator::analyticsSummaryVersion($t->id)))->not->toBeNull();
});

it('is idempotent across an at-least-once retry (handle twice → one row)', function () {
    $t = createTenant('job-retry');
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

// #CFG-1 — tries/backoff/timeout are assigned in the constructor from config (typed
// properties can't call config() at declaration), so an override must flow through.
it('reads tries/backoff/timeout from config at construction time', function () {
    config([
        'partna.analytics.job_tries' => 7,
        'partna.analytics.job_backoff_seconds' => 42,
        'partna.analytics.job_timeout_seconds' => 99,
    ]);

    $job = new RecordAnalyticsEventJob(['id' => 'x']);

    expect($job->tries)->toBe(7)
        ->and($job->backoff)->toBe(42)
        ->and($job->timeout)->toBe(99);
});

it('defaults tries/backoff/timeout to the pre-CFG-1 hardcoded values', function () {
    $job = new RecordAnalyticsEventJob(['id' => 'x']);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe(10)
        ->and($job->timeout)->toBe(30);
});

// FOUND-5 guard: payload is AnalyticsEvent::toArray(), whose key is 'type' — a
// regression back to reading 'event_type' would make this fail (logs 'unknown').
it('logs the real event type, site_id, and user_id on permanent failure', function () {
    Exceptions::fake();
    Log::spy();

    $event = new AnalyticsEvent(
        id: (string) Str::orderedUuid(),
        type: AnalyticsEvent::TYPE_CLICK,
        occurredAt: now()->toISOString(),
        userId: 'user-123',
        siteId: 'site-456',
        sessionId: null,
        visitorId: null,
        ipHash: null,
        userAgent: null,
        referrer: null,
        utmSource: null,
        utmMedium: null,
        utmCampaign: null,
        countryCode: null,
        deviceType: null,
        blockId: null,
        sectionKey: null,
    );

    (new RecordAnalyticsEventJob($event->toArray()))->failed(new RuntimeException('boom'));

    Exceptions::assertReported(RuntimeException::class);
    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'Analytics event permanently dropped'
            && ($context['event_type'] ?? null) === 'click'
            && ($context['site_id'] ?? null) === 'site-456'
            && ($context['user_id'] ?? null) === 'user-123');
});
