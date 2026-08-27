<?php

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Services\Site\SectionBlockProvisioner;

/**
 * T15 (2026-08-27 unclaimed-signup quality plan, issue 9): section blocks were
 * only ever provisioned by the dashboard's GET /api/sections — so a
 * pre-account build that wrote a perfectly good workplace row (the Fresha →
 * Places linker) had NO `workplace` block, and sectionEnvelope() kept the
 * workplace invisible on the live site forever ("linked but invisible",
 * verified on barber-in-law 2026-08-27).
 *
 * The provisioning loop is now a shared service the controller delegates to,
 * so the pre-account pipeline can provision the same rows with the same
 * seeding rules — and WorkplaceObserver re-evaluates (or provisions) the
 * workplace block when workplace data lands AFTER the blocks were seeded,
 * which is the normal order for the Fresha linker (~3 min after generate).
 */
beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    setupMediaTables();
    setupWorkplacesTable();
    setupSectionsTables();
    setupContentCurationTables();
    setupIntegrationConnectionsTable();
    shimPgAdvisoryLockForSqlite();
});

function sbpSiteFor(string $handle): array
{
    $pro = createTenant($handle);
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();

    return [$pro, $site];
}

it('provisions the full allowed section set without any dashboard call, with the two owner-ruled sections live', function () {
    [$pro, $site] = sbpSiteFor('sbp-prov');

    $created = app(SectionBlockProvisioner::class)->syncAllowed(
        (string) $pro->id,
        (string) $site->id,
        config('partna.section_block_types', []),
    );

    $blocks = Block::query()
        ->where('user_id', $pro->id)
        ->where('block_group', Block::GROUP_SECTIONS)
        ->get()
        ->keyBy('block_type');

    expect($blocks->keys()->sort()->values()->all())
        ->toBe(collect(config('partna.section_block_types', []))->sort()->values()->all());

    // The 2026-08-19 owner ruling, unchanged: workplace + public_contact are
    // opt-OUT (live), everything else starts draft.
    expect((bool) $blocks['workplace']->is_active)->toBeTrue();
    expect((bool) $blocks['public_contact']->is_active)->toBeTrue();
    expect((bool) $blocks['gallery']->is_active)->toBeFalse();

    // No workplace data yet → honestly disabled until data arrives.
    expect((bool) $blocks['workplace']->is_enabled)->toBeFalse();
});

it('is idempotent — a second sync neither duplicates rows nor touches existing state', function () {
    [$pro, $site] = sbpSiteFor('sbp-idem');
    $svc = app(SectionBlockProvisioner::class);
    $allowed = config('partna.section_block_types', []);

    $svc->syncAllowed((string) $pro->id, (string) $site->id, $allowed);

    // Simulate the owner flipping workplace off — a re-sync must not undo it.
    Block::query()->where('user_id', $pro->id)->where('block_type', 'workplace')
        ->update(['is_active' => false]);

    $svc->syncAllowed((string) $pro->id, (string) $site->id, $allowed);

    $count = Block::query()->where('user_id', $pro->id)->where('block_group', Block::GROUP_SECTIONS)->count();
    expect($count)->toBe(count($allowed));
    expect((bool) Block::query()->where('user_id', $pro->id)->where('block_type', 'workplace')->value('is_active'))->toBeFalse();
});

it('enables the workplace block when workplace data arrives after provisioning (the Fresha-linker order)', function () {
    [$pro, $site] = sbpSiteFor('sbp-late');

    app(SectionBlockProvisioner::class)->syncAllowed(
        (string) $pro->id, (string) $site->id, config('partna.section_block_types', []),
    );
    expect((bool) Block::query()->where('user_id', $pro->id)->where('block_type', 'workplace')->value('is_enabled'))->toBeFalse();

    // The linker's write, minutes later. site_id is deliberately not
    // fillable (#SEC-17) — assigned explicitly, as every real writer does.
    $workplace = new Workplace([
        'name' => 'Studio San',
        'address_line1' => '159 Eley Rd',
        'city' => 'Blackburn South',
    ]);
    $workplace->site_id = $site->id;
    $workplace->save();

    $block = Block::query()->where('user_id', $pro->id)->where('block_type', 'workplace')->first();
    expect((bool) $block->is_enabled)->toBeTrue();
    expect((bool) $block->is_active)->toBeTrue();
});

it('provisions the workplace block on workplace creation even when no blocks exist yet', function () {
    [$pro, $site] = sbpSiteFor('sbp-none');

    $workplace = new Workplace([
        'name' => 'Star Barber Darwin',
        'address_line1' => 'Shop 6 Star Village Arcade',
        'city' => 'Darwin',
    ]);
    $workplace->site_id = $site->id;
    $workplace->save();

    $block = Block::query()
        ->where('user_id', $pro->id)
        ->where('block_group', Block::GROUP_SECTIONS)
        ->where('block_type', 'workplace')
        ->first();

    expect($block)->not->toBeNull();
    expect((bool) $block->is_active)->toBeTrue();
    expect((bool) $block->is_enabled)->toBeTrue();
});
