<?php

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Services\PreAccount\ContactFormSeeder;
use App\Services\Site\SectionBlockProvisioner;

/**
 * T20 (owner, 2026-08-27): the contact/enquiry form is ENABLED BY DEFAULT on
 * unclaimed sites — pre-claim, submissions route to the public contact email
 * when one exists (the form stays honestly dark when there is none: the
 * ContactVisibility rule requires a routable email); at claim, an
 * auto-seeded or empty notification email defaults to the account's own
 * email, and an owner-typed one is never touched.
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

function cfdUser(string $handle, ?string $publicEmail): array
{
    $pro = createTenant($handle);
    if ($publicEmail !== null) {
        $pro->forceFill(['public_contact_email' => $publicEmail])->save();
    }
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    app(SectionBlockProvisioner::class)->syncAllowed((string) $pro->id, (string) $site->id, config('partna.section_block_types', []));

    return [$pro->fresh(), $site];
}

function cfdContactBlock(string $userId): Block
{
    return Block::query()->where('user_id', $userId)
        ->where('block_group', Block::GROUP_SECTIONS)->where('block_type', 'contact')->firstOrFail();
}

it('seeds a live, routable contact form from the public contact email on a build', function () {
    [$pro] = cfdUser('cfd-with', 'emma@starbarber.example');

    app(ContactFormSeeder::class)->seedForBuild($pro);

    $block = cfdContactBlock((string) $pro->id);
    expect((bool) $block->is_active)->toBeTrue();
    expect(data_get($block->settings, 'notification_email'))->toBe('emma@starbarber.example');
    expect(data_get($block->settings, 'notification_email_source'))->toBe('auto');
    expect((bool) $block->is_enabled)->toBeTrue();
});

it('activates the form but leaves it honestly disabled when no public email exists', function () {
    [$pro] = cfdUser('cfd-none', null);

    app(ContactFormSeeder::class)->seedForBuild($pro);

    $block = cfdContactBlock((string) $pro->id);
    expect((bool) $block->is_active)->toBeTrue();
    expect(data_get($block->settings, 'notification_email'))->toBeNull();
    expect((bool) $block->is_enabled)->toBeFalse();
});

it('claim defaults an auto-seeded email to the account email; an owner-typed one is never touched', function () {
    // Auto-seeded pre-claim → replaced at claim.
    [$auto] = cfdUser('cfd-auto', 'public@bio.example');
    app(ContactFormSeeder::class)->seedForBuild($auto);
    $auto->forceFill(['primary_email' => 'me@real.example'])->save();
    app(ContactFormSeeder::class)->applyClaimDefault($auto->fresh());
    expect(data_get(cfdContactBlock((string) $auto->id)->settings, 'notification_email'))->toBe('me@real.example');

    // Never seeded (no public email) → set at claim.
    [$empty] = cfdUser('cfd-empty', null);
    app(ContactFormSeeder::class)->seedForBuild($empty);
    $empty->forceFill(['primary_email' => 'owner@real.example'])->save();
    app(ContactFormSeeder::class)->applyClaimDefault($empty->fresh());
    $block = cfdContactBlock((string) $empty->id);
    expect(data_get($block->settings, 'notification_email'))->toBe('owner@real.example');
    expect((bool) $block->is_enabled)->toBeTrue();

    // Owner-typed → untouched.
    [$owned] = cfdUser('cfd-owned', null);
    $block = cfdContactBlock((string) $owned->id);
    $block->settings = ['notification_email' => 'custom@owner.example'];
    $block->save();
    $owned->forceFill(['primary_email' => 'acct@real.example'])->save();
    app(ContactFormSeeder::class)->applyClaimDefault($owned->fresh());
    expect(data_get(cfdContactBlock((string) $owned->id)->settings, 'notification_email'))->toBe('custom@owner.example');
});
