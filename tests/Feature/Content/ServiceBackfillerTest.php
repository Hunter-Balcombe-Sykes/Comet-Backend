<?php

use App\Services\Migration\ServiceBackfiller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Slice 3a §3.1: the 21 owner-authored services become service-kind content
// items through the slice-0b manual lane. Production code, idempotent,
// re-runnable (convergence invariant #4).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    Queue::fake();
});

// createTenant() (tests/Pest.php) already seeds a core.users + site.sites
// pair — reused here instead of a bespoke helper (see poolTenant() in
// PoolLaneTest.php for the same pattern). ownerService() lives in
// tests/Pest.php, not this file: cross-file global function definitions
// depend on Pest's load order, and later slice-3 tasks' test files need it
// too.
function serviceOwner(): string
{
    return createTenant('svc-'.Str::lower(Str::random(8)))->id;
}

it('lands an owner-authored service as a content item on the manual source', function () {
    $userId = serviceOwner();
    $serviceId = ownerService($userId);

    $result = app(ServiceBackfiller::class)->run();

    expect($result['backfilled'])->toBe(1);

    $row = DB::table('content.source_items as si')
        ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
        ->join('content.items as i', 'i.id', '=', 'si.item_id')
        ->where('si.coord', 'manual:'.$serviceId)
        ->first(['cs.kind as source_kind', 'si.kind', 'i.headline_cache', 'i.removed_at', 'i.id as item_id']);

    expect($row->source_kind)->toBe('manual');
    expect($row->kind)->toBe('service');
    expect($row->headline_cache)->toBe('Consultation');
    expect($row->removed_at)->toBeNull();
});

it('writes price as an exact offer and duration in seconds', function () {
    $userId = serviceOwner();
    ownerService($userId, ['price_cents' => 12500, 'duration_minutes' => 90]);

    app(ServiceBackfiller::class)->run();

    $offer = DB::table('content.offers')->first();
    expect((int) $offer->amount_minor)->toBe(12500);
    expect($offer->currency)->toBe('AUD');
    expect($offer->qualifier)->toBe('exact');

    expect((int) DB::table('content.f_duration')->value('seconds'))->toBe(5400);
});

it('maps a hand-entered zero price to free, not exact-zero', function () {
    // §1.2: a TYPED zero means free. A SCRAPED zero means unparsed — that is
    // 3b's problem and must not be solved with this mapper.
    $userId = serviceOwner();
    ownerService($userId, ['price_cents' => 0]);

    app(ServiceBackfiller::class)->run();

    expect(DB::table('content.offers')->value('qualifier'))->toBe('free');
});

it('omits duration and body rows when the legacy columns are null', function () {
    $userId = serviceOwner();
    ownerService($userId, ['duration_minutes' => null, 'description' => null]);

    app(ServiceBackfiller::class)->run();

    expect(DB::table('content.f_duration')->count())->toBe(0);
    expect(DB::table('content.f_text')->value('body'))->toBeNull();
});

it('carries a soft delete to items.removed_at and NEVER to source_items', function () {
    // The whole point: source_items.removed_at is cleared on reappearance, so
    // writing it there would resurrect a service its owner deleted.
    $userId = serviceOwner();
    ownerService($userId, ['deleted_at' => now()]);

    $result = app(ServiceBackfiller::class)->run();

    expect($result['retired'])->toBe(1);
    expect(DB::table('content.items')->value('removed_at'))->not->toBeNull();
    expect(DB::table('content.source_items')->whereNotNull('removed_at')->count())->toBe(0);
});

it('ignores fresha-sourced rows entirely', function () {
    // §2: the 61 Fresha rows are NOT backfilled — the connector lands them.
    $userId = serviceOwner();
    ownerService($userId, ['source' => 'fresha', 'external_id' => 's:123']);

    $result = app(ServiceBackfiller::class)->run();

    expect($result['backfilled'])->toBe(0);
    expect(DB::table('content.items')->count())->toBe(0);
});

it('is idempotent across two runs', function () {
    $userId = serviceOwner();
    ownerService($userId);

    app(ServiceBackfiller::class)->run();
    app(ServiceBackfiller::class)->run();

    expect(DB::table('content.source_items')->count())->toBe(1);
    expect(DB::table('content.items')->count())->toBe(1);
    expect(DB::table('content.offers')->count())->toBe(1);
});

it('writes nothing under dry run but still counts', function () {
    $userId = serviceOwner();
    ownerService($userId);

    $result = app(ServiceBackfiller::class)->run(dryRun: true);

    expect($result['backfilled'])->toBe(1);
    expect(DB::table('content.items')->count())->toBe(0);
});
