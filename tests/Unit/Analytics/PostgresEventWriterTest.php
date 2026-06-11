<?php

// tests/Unit/Analytics/PostgresEventWriterTest.php

use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Writers\PostgresEventWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class); // Unit test hits the (sqlite-backed) pgsql connection.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    setupSiteVisitsTable();
    setupLinkClicksTable();
    setupSectionViewsTable();
});

function pgWriter(): PostgresEventWriter
{
    return new PostgresEventWriter;
}

function baseEvent(array $o = []): AnalyticsEvent
{
    return AnalyticsEvent::fromArray(array_merge([
        'id' => (string) Str::orderedUuid(),
        'type' => AnalyticsEvent::TYPE_PAGEVIEW,
        'occurred_at' => now()->toISOString(),
        'user_id' => 'u', 'site_id' => 's',
        'session_id' => null, 'visitor_id' => null, 'ip_hash' => null,
        'user_agent' => null, 'referrer' => null,
        'utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null,
        'country_code' => null, 'device_type' => null,
        'block_id' => null, 'section_key' => null,
    ], $o));
}

it('persists a pageview to site_visits', function () {
    $t = createBrandTenant('writer-pv');
    pgWriter()->write(baseEvent(['user_id' => $t->id, 'site_id' => $t->site->id, 'country_code' => 'AU']));

    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(1);
});

it('is idempotent — the same minted id inserts exactly one row', function () {
    $t = createBrandTenant('writer-idem');
    $e = baseEvent(['user_id' => $t->id, 'site_id' => $t->site->id]);
    pgWriter()->write($e);
    pgWriter()->write($e); // at-least-once retry

    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(1);
});

it('persists a click for a trackable active block, mapping link_block_id', function () {
    $t = createBrandTenant('writer-click');
    $block = createLinkBlockFor($t);
    pgWriter()->write(baseEvent([
        'type' => AnalyticsEvent::TYPE_CLICK, 'user_id' => $t->id, 'site_id' => $t->site->id, 'block_id' => $block->id,
    ]));

    $row = DB::connection('pgsql')->table('analytics.link_clicks')->first();
    expect($row->link_block_id)->toBe($block->id);
});

it('drops a click whose block does not exist', function () {
    $t = createBrandTenant('writer-missing');
    pgWriter()->write(baseEvent([
        'type' => AnalyticsEvent::TYPE_CLICK, 'user_id' => $t->id, 'site_id' => $t->site->id, 'block_id' => (string) Str::uuid(),
    ]));

    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(0);
});

it('drops a click whose block belongs to another site (IDOR defence at the writer)', function () {
    $t = createBrandTenant('writer-foreign');
    $other = createBrandTenant('writer-foreign-other');
    $foreign = createLinkBlockFor($other);
    pgWriter()->write(baseEvent([
        'type' => AnalyticsEvent::TYPE_CLICK, 'user_id' => $t->id, 'site_id' => $t->site->id, 'block_id' => $foreign->id,
    ]));

    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(0);
});

it('drops a click whose block is inactive', function () {
    $t = createBrandTenant('writer-inactive');
    $block = createLinkBlockFor($t, ['is_active' => 0]);
    pgWriter()->write(baseEvent([
        'type' => AnalyticsEvent::TYPE_CLICK, 'user_id' => $t->id, 'site_id' => $t->site->id, 'block_id' => $block->id,
    ]));

    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(0);
});

it('drops a click whose block is not trackable', function () {
    $t = createBrandTenant('writer-untrackable');
    $block = createLinkBlockFor($t, ['block_group' => 'sections', 'block_type' => 'custom_html']);
    pgWriter()->write(baseEvent([
        'type' => AnalyticsEvent::TYPE_CLICK, 'user_id' => $t->id, 'site_id' => $t->site->id, 'block_id' => $block->id,
    ]));

    expect(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(0);
});

it('persists a section view with a null block_id', function () {
    $t = createBrandTenant('writer-section-null');
    pgWriter()->write(baseEvent([
        'type' => AnalyticsEvent::TYPE_SECTION_VIEW, 'user_id' => $t->id, 'site_id' => $t->site->id,
        'section_key' => 'hero', 'block_id' => null,
    ]));

    expect(DB::connection('pgsql')->table('analytics.section_views')->count())->toBe(1);
});

it('drops a section view whose block belongs to another site', function () {
    $t = createBrandTenant('writer-section-foreign');
    $other = createBrandTenant('writer-section-foreign-other');
    $foreign = createLinkBlockFor($other);
    pgWriter()->write(baseEvent([
        'type' => AnalyticsEvent::TYPE_SECTION_VIEW, 'user_id' => $t->id, 'site_id' => $t->site->id,
        'section_key' => 'products', 'block_id' => $foreign->id,
    ]));

    expect(DB::connection('pgsql')->table('analytics.section_views')->count())->toBe(0);
});

it('writeMany persists all valid events across types', function () {
    $t = createBrandTenant('writer-many');
    $block = createLinkBlockFor($t);
    pgWriter()->writeMany([
        baseEvent(['user_id' => $t->id, 'site_id' => $t->site->id]),
        baseEvent(['type' => AnalyticsEvent::TYPE_CLICK, 'user_id' => $t->id, 'site_id' => $t->site->id, 'block_id' => $block->id]),
        baseEvent(['type' => AnalyticsEvent::TYPE_SECTION_VIEW, 'user_id' => $t->id, 'site_id' => $t->site->id, 'section_key' => 'hero']),
    ]);

    expect(DB::connection('pgsql')->table('analytics.site_visits')->count())->toBe(1)
        ->and(DB::connection('pgsql')->table('analytics.link_clicks')->count())->toBe(1)
        ->and(DB::connection('pgsql')->table('analytics.section_views')->count())->toBe(1);
});
