<?php

// The property, and the reason this file exists instead of a longer list of
// known bad paths: NO SENTENCE the generated privacy policy publishes may
// assert behaviour the platform does not actually perform — in EVERY reachable
// configuration, not the default one and not the current one.
//
// The text goes out on a real business's sitepage under that business's name,
// so each of these was that business making a false statement about itself, and
// each was found one at a time after the last one was "fixed": a raw retention
// window under the floor (the command aborts, nothing is deleted), a derived
// content_popularity_scores window under the floor (same abort, but the policy
// was only checking the raw window), a purge batch size of zero (LIMIT 0 inside
// purgeBatched(), which deleted nothing while the command still exited 0), and a
// location precision knob with no floor under it (round() to 4dp or more is a
// no-op on values DetectsClientInfo already rounded to 4dp — nothing is
// "rounded down in precision" at all).
//
// Chasing them one at a time is what kept failing. So the sweep below does not
// know about any of them. It takes the knobs that feed the published claims,
// crosses every interesting value of each — under the threshold, EXACTLY on it
// (the boundary a `<` → `<=` mutant lives or dies on), and past it — and for
// each of the 81 resulting configurations checks both halves of the property:
//
//   1. the text makes a behavioural claim only when the lane that performs it
//      says it will (SitePolicyResolver::behaviouralGuarantees()), and
//   2. that answer is not taken on trust — the lane is RUN in that same
//      configuration and its actual effect on rows is what the guarantee is
//      measured against.
//
// A new abort path or a new knob therefore has to make some lane's own answer
// wrong to slip through, rather than merely being absent from a list here.

use App\Console\Commands\PurgeRawAnalyticsEvents;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Writers\PostgresEventWriter;
use App\Services\Site\SitePolicyResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    attachTestSchemas();
    setupSiteVisitsTable();
    setupLinkClicksTable();
    setupSiteSessionsTable();
    setupSectionViewsTable();
    setupItemViewsTable();
    setupActionEventsTable();
    setupContentPopularityScoresTable();

    // No shared Pest.php helper for this table — mirrors the minimal DDL used by
    // PurgeRawAnalyticsEventsCommandTest, which purges the same seven raw tables.
    DB::connection('pgsql')->statement('DROP TABLE IF EXISTS analytics.lead_submissions');
    DB::connection('pgsql')->statement('CREATE TABLE analytics.lead_submissions (
        id TEXT PRIMARY KEY,
        occurred_at TEXT NULL
    )');
});

// Distinct names on purpose: Pest loads every test file into ONE process, so a
// helper sharing a name with SitePolicyResolverTest's policyUser()/policySite()
// would be a fatal redeclaration rather than a test failure.
function truthPolicyUser(): User
{
    return new User([
        'handle' => 'jane-doe',
        'display_name' => 'Jane Doe',
        'account_type' => 'partna',
    ]);
}

function truthPolicySite(): Site
{
    return new Site(['settings' => []]);
}

/**
 * Run the SCHEDULED purge (no --days: the policy describes the scheduled run,
 * not an operator's one-off) against one row either side of the window the
 * policy would publish, and report whether the stale row actually went.
 *
 * Deliberately returns an observation, not an assertion, so the caller can hold
 * it against what the lane CLAIMED. The one thing asserted here is the control:
 * a row inside the window must survive in every configuration, because a purge
 * that took everything would satisfy "the stale row is gone" while making the
 * published window a lie in the other direction.
 */
function purgeActuallyDeletesStaleEvents(int $windowDays): bool
{
    DB::connection('pgsql')->table('analytics.site_visits')->delete();

    $stale = (string) Str::uuid();
    $inside = (string) Str::uuid();

    DB::connection('pgsql')->table('analytics.site_visits')->insert([
        ['id' => $stale, 'occurred_at' => now()->subDays($windowDays + 5)->toDateTimeString()],
        ['id' => $inside, 'occurred_at' => now()->subDays(max(1, $windowDays - 5))->toDateTimeString()],
    ]);

    Artisan::call('partna:analytics:purge-raw-events');

    $survivors = DB::connection('pgsql')->table('analytics.site_visits')->pluck('id')->all();

    expect($survivors)->toContain($inside);

    return ! in_array($stale, $survivors, true);
}

/**
 * Write one visit through the real writer and report whether the coordinate
 * that came out is coarser than the one that went in.
 *
 * The input is 4dp because that is exactly what DetectsClientInfo::parseCoordinate()
 * hands this lane — the most precise value the writer can ever be asked to store,
 * and therefore the only input that can tell coarsening apart from a round() call
 * that changes nothing.
 */
function writerActuallyCoarsensCoordinates(): bool
{
    DB::connection('pgsql')->table('analytics.site_visits')->delete();

    $ingested = -37.8136;

    (new PostgresEventWriter)->write(AnalyticsEvent::fromArray([
        'id' => (string) Str::orderedUuid(),
        'type' => AnalyticsEvent::TYPE_PAGEVIEW,
        'occurred_at' => now()->toISOString(),
        'user_id' => 'u', 'site_id' => 's',
        'session_id' => null, 'visitor_id' => null, 'ip_hash' => null,
        'user_agent' => null, 'referrer' => null,
        'utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null,
        'country_code' => null, 'device_type' => null,
        'block_id' => null, 'section_key' => null,
        'latitude' => $ingested,
        'longitude' => 144.9630,
    ]));

    $stored = (float) DB::connection('pgsql')->table('analytics.site_visits')->value('latitude');

    DB::connection('pgsql')->table('analytics.site_visits')->delete();

    return $stored !== $ingested;
}

dataset('reachable analytics configurations', function () {
    $retentionFloor = PurgeRawAnalyticsEvents::MINIMUM_RETENTION_DAYS;
    $batchFloor = PurgeRawAnalyticsEvents::MINIMUM_PURGE_BATCH_SIZE;
    $areaCeiling = PostgresEventWriter::MAXIMUM_AREA_PRECISION_DECIMALS;

    // Per knob: one value the threshold rejects, the threshold ITSELF (the case
    // the old suite never ran, which is why `<` and `<=` were indistinguishable
    // in every guard), and one ordinary production value.
    $rawDays = [$retentionFloor - 1, $retentionFloor, 90];
    $scoresDays = [$retentionFloor - 1, $retentionFloor, 180];
    $batchSizes = [$batchFloor - 1, $batchFloor, 10_000];
    $precisions = [$areaCeiling + 1, $areaCeiling, 2];

    foreach ($rawDays as $raw) {
        foreach ($scoresDays as $scores) {
            foreach ($batchSizes as $batch) {
                foreach ($precisions as $decimals) {
                    yield "raw={$raw} scores={$scores} batch={$batch} decimals={$decimals}" => [$raw, $scores, $batch, $decimals];
                }
            }
        }
    }
});

it('publishes a behavioural claim only when the lane that performs it says it will', function (
    int $rawDays,
    int $scoresDays,
    int $batchSize,
    int $decimals,
) {
    config()->set('partna.analytics_raw_event_retention_days', $rawDays);
    config()->set('partna.analytics.content_popularity_scores_retention_days', $scoresDays);
    config()->set('partna.analytics.purge_batch_size', $batchSize);
    config()->set('partna.analytics.location_precision_decimals', $decimals);

    $resolved = app(SitePolicyResolver::class)->resolve(truthPolicyUser(), truthPolicySite());
    $published = $resolved['privacy']['text'];
    $preview = app(SitePolicyResolver::class)->autoTexts(truthPolicyUser(), truthPolicySite())['privacy'];

    foreach (SitePolicyResolver::behaviouralGuarantees() as $phrase => $guaranteed) {
        // Both directions. `contains → guaranteed` is the property itself; the
        // converse catches a policy that quietly drops a true claim, which is a
        // different bug but the same file's job.
        expect(str_contains($published, $phrase))->toBe($guaranteed)
            // The dashboard preview and the public payload are the same text or
            // one of them is lying to the owner about what their site publishes.
            ->and(str_contains($preview, $phrase))->toBe($guaranteed);
    }
})->with('reachable analytics configurations');

it('backs every guarantee with the lane actually doing it in that same configuration', function (
    int $rawDays,
    int $scoresDays,
    int $batchSize,
    int $decimals,
) {
    config()->set('partna.analytics_raw_event_retention_days', $rawDays);
    config()->set('partna.analytics.content_popularity_scores_retention_days', $scoresDays);
    config()->set('partna.analytics.purge_batch_size', $batchSize);
    config()->set('partna.analytics.location_precision_decimals', $decimals);

    // The guarantees are answers, and an answer is worth nothing until the lane
    // has been run. Everything above this line is prose agreeing with prose.
    expect(purgeActuallyDeletesStaleEvents($rawDays))
        ->toBe(PurgeRawAnalyticsEvents::scheduledPurgeWouldDelete())
        ->and(writerActuallyCoarsensCoordinates())
        ->toBe(PostgresEventWriter::coordinatesAreCoarsened());
})->with('reachable analytics configurations');

// The precision ceiling is only defensible because of what the ingest lane
// already did to the number before the writer sees it. If parseCoordinate() ever
// stops rounding to 4dp, MAXIMUM_AREA_PRECISION_DECIMALS stops meaning what its
// docblock says and the "no-op above this" argument silently evaporates.
it('pins the ingest precision the writer\'s area ceiling is reasoned from', function () {
    expect(PostgresEventWriter::INGEST_PRECISION_DECIMALS)->toBe(4)
        ->and(PostgresEventWriter::MAXIMUM_AREA_PRECISION_DECIMALS)
        ->toBeLessThan(PostgresEventWriter::INGEST_PRECISION_DECIMALS);

    $source = file_get_contents(app_path('Http/Controllers/Concerns/DetectsClientInfo.php'));

    expect($source)->toContain('return round($parsed, '.PostgresEventWriter::INGEST_PRECISION_DECIMALS.');');
});

// A batch size of zero used to be a HANG, not a failure: purgeBatched() looped
// while ($count === $batchSize), and a LIMIT 0 delete returns 0, so 0 === 0 spun
// forever with nothing deleted. A guard that only aborts is not enough — a future
// caller that skips the guard must still terminate — so the loop condition itself
// is pinned here.
it('terminates instead of spinning when a batch comes back empty', function () {
    $purgeBatched = (new ReflectionClass(PurgeRawAnalyticsEvents::class))->getMethod('purgeBatched');
    $purgeBatched->setAccessible(true);

    DB::connection('pgsql')->table('analytics.site_visits')->insert([
        ['id' => (string) Str::uuid(), 'occurred_at' => now()->subDays(500)->toDateTimeString()],
    ]);

    $deleted = $purgeBatched->invoke(
        new PurgeRawAnalyticsEvents,
        'analytics.site_visits',
        'occurred_at',
        now()->subDays(90)->toImmutable(),
        0,
    );

    expect($deleted)->toBe(0)
        ->and(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(1);
});
