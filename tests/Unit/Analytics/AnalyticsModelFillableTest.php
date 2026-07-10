<?php

// tests/Unit/Analytics/AnalyticsModelFillableTest.php
//
// #PARITY-1 guard (supersedes the narrower FOUND-4 guard this file used to be):
// each analytics event model's $fillable must mirror EXACTLY the columns
// PostgresEventWriter actually writes for that event type, minus a small
// GUARDED set of tenancy/PK/timestamp columns that must never be
// mass-assignable. The writer itself uses insertOrIgnore() with raw arrays
// (bypasses $fillable entirely), so nothing is broken in production today —
// this guards a FUTURE ::create()/fill() caller from either:
//   (a) silently dropping a real column to null — PARITY-1 caught this for
//       real: SiteVisit::$fillable never got latitude/longitude after the
//       20260707020000 lat/lon migration wired them into visitRow().
//   (b) reintroducing a spoofable tenancy/ownership FK — the SEC-1 pattern:
//       create()/fill() both route through $fillable, so trusted writers use
//       ->relation()->associate() or a raw array insert instead (see
//       CLAUDE.md "Fillable Tenancy-FK -> associate()").
//
// Derived, not hardcoded: rather than listing expected columns by hand (the
// exact gap that let PARITY-1 happen), this test reflects into
// PostgresEventWriter's private row-builder methods and computes the writer's
// REAL key set for each event type. A future column added to the writer
// without a matching $fillable (or GUARDED) change fails HERE.
//
// Out of scope: sectionDwell()/upsertSession() write via raw
// DB::table(...)/DB::statement(...) against analytics.section_views /
// analytics.site_sessions, not through an Eloquent model — there is no
// $fillable to drift, so they're not covered here.

use App\Models\Analytics\ItemView;
use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SectionView;
use App\Models\Analytics\SiteVisit;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Writers\PostgresEventWriter;
use Tests\TestCase;

// visitRow()/clickRow() call now()->toISOString() for created_at — needs the
// app container bound (Date facade), unlike plain-PHP unit tests elsewhere in
// this directory.
uses(TestCase::class);

// Columns the writer sets directly, that must NEVER be reachable via mass
// assignment on ANY analytics model:
const ANALYTICS_MODEL_GUARDED_COLUMNS = [
    // UUID PK minted by the writer and used as the insertOrIgnore idempotency
    // key — a mass-assignment caller supplying its own id could collide with
    // (and no-op over) another row.
    'id',
    // Tenancy FK — resolved server-side from the authenticated request
    // context, never from client-controlled input (SEC-1 pattern).
    'user_id',
    // Tenancy FK — same reasoning as user_id.
    'site_id',
    // Ownership FK to site.blocks on LinkClick. The writer validates the
    // referenced block belongs to the same site before persisting; a
    // mass-assignment path would skip that check and let a caller attribute
    // a click to another site's block (cross-tenant IDOR).
    'link_block_id',
    // Ownership FK to site.blocks on SectionView — identical risk to
    // link_block_id, just a different column name on this table.
    'block_id',
    // Timestamp owned by the writer, never client-supplied.
    'created_at',
];

/**
 * One AnalyticsEvent with every constructor field populated, so reflecting
 * into any writer row-builder yields a row with every optional key present
 * (no accidental key-omission from an unset nullable field masking a real
 * gap). blockId is left null so appendSectionRow()'s no-block branch runs
 * (skips the DB block lookup) — the row *shape* it builds is identical
 * either way, only the validation path differs.
 */
function fullyPopulatedAnalyticsEvent(): AnalyticsEvent
{
    return new AnalyticsEvent(
        id: 'evt-1',
        type: AnalyticsEvent::TYPE_PAGEVIEW,
        occurredAt: '2026-07-10T00:00:00.000000Z',
        userId: 'user-1',
        siteId: 'site-1',
        sessionId: 'sess-1',
        visitorId: 'vis-1',
        ipHash: 'hash',
        userAgent: 'UA',
        referrer: 'https://ref.test',
        utmSource: 'src',
        utmMedium: 'med',
        utmCampaign: 'camp',
        countryCode: 'AU',
        deviceType: 'mobile',
        blockId: null,
        sectionKey: 'shop',
        regionCode: 'NSW',
        city: 'Sydney',
        url: 'https://x.test',
        platform: 'instagram',
        productId: 'p1',
        productTitle: 'title',
        label: 'label',
        durationSeconds: 30,
        latitude: -33.8,
        longitude: 151.2,
        itemType: 'shop_product',
        itemId: 'item-1',
        itemTitle: 'item title',
        durationMs: 500,
    );
}

/**
 * Reflects into one private PostgresEventWriter row-builder and returns the
 * array keys of the row it produces for a fully-populated event.
 *
 * visitRow()/clickRow() return their row array directly. appendSectionRow()/
 * appendItemRow() are void and append onto a by-reference $rows array
 * instead — invokeArgs() honours a reference passed inside the args array,
 * so we build that array with `&$rows` as its last element for those two.
 */
function writerRowKeys(string $method): array
{
    $writer = new PostgresEventWriter;
    $event = fullyPopulatedAnalyticsEvent();
    $ref = new ReflectionMethod(PostgresEventWriter::class, $method);
    $ref->setAccessible(true);

    if (in_array($method, ['visitRow', 'clickRow'], true)) {
        return array_keys($ref->invoke($writer, $event));
    }

    $rows = [];
    $args = $method === 'appendSectionRow' ? [$event, []] : [$event];
    $args[] = &$rows;
    $ref->invokeArgs($writer, $args);

    expect($rows)->toHaveCount(1); // sanity: the event must not have been dropped

    return array_keys($rows[0]);
}

/** @return array{0: array<string>, 1: array<string>} [sorted actual, sorted expected] */
function sortedFillableVsExpected(string $modelClass, string $writerMethod): array
{
    $writerKeys = writerRowKeys($writerMethod);
    $expected = array_values(array_diff($writerKeys, ANALYTICS_MODEL_GUARDED_COLUMNS));
    $actual = (new $modelClass)->getFillable();

    sort($expected);
    sort($actual);

    return [$actual, $expected];
}

it('LinkClick::$fillable exactly matches clickRow() keys minus the guarded columns', function () {
    [$actual, $expected] = sortedFillableVsExpected(LinkClick::class, 'clickRow');

    expect($actual)->toEqual($expected);
});

it('SiteVisit::$fillable exactly matches visitRow() keys minus the guarded columns', function () {
    [$actual, $expected] = sortedFillableVsExpected(SiteVisit::class, 'visitRow');

    expect($actual)->toEqual($expected);
});

it('SectionView::$fillable exactly matches appendSectionRow() keys minus the guarded columns', function () {
    [$actual, $expected] = sortedFillableVsExpected(SectionView::class, 'appendSectionRow');

    expect($actual)->toEqual($expected);
});

it('ItemView::$fillable exactly matches appendItemRow() keys minus the guarded columns', function () {
    [$actual, $expected] = sortedFillableVsExpected(ItemView::class, 'appendItemRow');

    expect($actual)->toEqual($expected);
});

it('keeps every guarded tenancy/PK/timestamp column un-fillable on all four analytics models', function () {
    foreach ([LinkClick::class, SiteVisit::class, SectionView::class, ItemView::class] as $modelClass) {
        $model = new $modelClass;
        foreach (ANALYTICS_MODEL_GUARDED_COLUMNS as $column) {
            expect($model->isFillable($column))
                ->toBeFalse("{$modelClass} must not allow mass-assigning guarded column '{$column}'");
        }
    }
});

it('actually mass-assigns every non-guarded writer column via fill() (not just present in $fillable)', function () {
    $event = fullyPopulatedAnalyticsEvent();

    $cases = [
        [LinkClick::class, 'clickRow'],
        [SiteVisit::class, 'visitRow'],
        [SectionView::class, 'appendSectionRow'],
        [ItemView::class, 'appendItemRow'],
    ];

    foreach ($cases as [$modelClass, $writerMethod]) {
        $writer = new PostgresEventWriter;
        $ref = new ReflectionMethod(PostgresEventWriter::class, $writerMethod);
        $ref->setAccessible(true);

        if (in_array($writerMethod, ['visitRow', 'clickRow'], true)) {
            $row = $ref->invoke($writer, $event);
        } else {
            $rows = [];
            $args = $writerMethod === 'appendSectionRow' ? [$event, []] : [$event];
            $args[] = &$rows;
            $ref->invokeArgs($writer, $args);
            $row = $rows[0];
        }

        $attributes = array_diff_key($row, array_flip(ANALYTICS_MODEL_GUARDED_COLUMNS));
        $model = (new $modelClass)->fill($attributes);

        foreach ($attributes as $key => $value) {
            $actual = $model->{$key};

            // occurred_at is cast to datetime, so fill() hands back a Carbon
            // instance rather than the original string — compare ISO strings.
            if ($actual instanceof Illuminate\Support\Carbon) {
                $actual = $actual->toISOString();
                $value = Illuminate\Support\Carbon::parse($value)->toISOString();
            }

            expect($actual)->toBe($value, "{$modelClass}::{$key} did not round-trip through fill()");
        }
    }
});
