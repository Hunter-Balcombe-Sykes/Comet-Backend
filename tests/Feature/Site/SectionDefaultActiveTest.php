<?php

use App\Models\Core\Site\Block;

/**
 * Which section types are seeded LIVE by UserSectionBlockController::
 * syncAllowedSections, and which start in draft.
 *
 * Sections start OFF and the owner publishes them. Two are exceptions by owner
 * ruling: `workplace` (2026-08-19) and `public_contact` (2026-08-19) — both
 * render on the sitepage the moment the underlying data exists, and the
 * dashboard switch for them is opt-OUT.
 *
 * `public_contact` is safe to default live BECAUSE of its visibility rule, not
 * in spite of it: PublicContactVisibility only sets is_enabled once
 * public_contact_number or public_contact_email is non-empty, and
 * SitepageDataResolverService::sectionEnvelope requires is_enabled AND
 * is_active. So a default-live block with no contact data still resolves to
 * `publicContact: null` on the wire — the flag decides what happens WHEN data
 * arrives, and never publishes an empty section.
 *
 * There was no test for the workplace default when it shipped, so it is pinned
 * here alongside. The negative cases are the point of the file: this is a list
 * that will be edited again, and a regression that flips a section like
 * `gallery` live by default publishes content silently.
 */
beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    // Every registered visibility rule contributes an EXISTS subquery to the
    // ONE context SELECT (SectionVisibilityService::buildContext), so seeding
    // ANY section needs the tables ALL of them read — gallery -> site.site_media,
    // workplace -> site.workplaces. A missing one 500s the whole index call.
    setupMediaTables();
    setupWorkplacesTable();
    // services -> content.items (the pool store; site.services was dropped in
    // the 2026-08-18 services cutover), and every pool query drags
    // source_items -> sources -> platform_connections in behind it via
    // LiveSourceScope, so the curation tables are needed too.
    setupSectionsTables();
    setupContentCurationTables();
    setupIntegrationConnectionsTable();
    shimPgAdvisoryLockForSqlite();
});

/** GET /api/sections is what seeds the rows (syncAllowedSections). */
function seedSectionsFor(string $handle): string
{
    $pro = createTenant($handle);
    actingAsUser($pro)->getJson('/api/sections')->assertOk();

    return (string) $pro->id;
}

dataset('defaultLiveSections', ['workplace', 'public_contact']);

it('seeds the section live', function (string $blockType) {
    $userId = seedSectionsFor('sec-live-'.str_replace('_', '-', $blockType));

    $block = Block::query()
        ->where('user_id', $userId)
        ->where('block_group', Block::GROUP_SECTIONS)
        ->where('block_type', $blockType)
        ->first();

    expect($block)->not->toBeNull("No `{$blockType}` section row was seeded at all.");
    expect((bool) $block->is_active)->toBeTrue(
        "`{$blockType}` must be seeded live — its dashboard switch is opt-OUT."
    );
})->with('defaultLiveSections');

dataset('defaultDraftSections', ['gallery', 'services', 'booking', 'documents', 'newsletter', 'contact']);

it('seeds the section in draft', function (string $blockType) {
    $userId = seedSectionsFor('sec-draft-'.str_replace('_', '-', $blockType));

    $block = Block::query()
        ->where('user_id', $userId)
        ->where('block_group', Block::GROUP_SECTIONS)
        ->where('block_type', $blockType)
        ->first();

    expect($block)->not->toBeNull("No `{$blockType}` section row was seeded at all.");
    expect((bool) $block->is_active)->toBeFalse(
        "`{$blockType}` must start in draft — defaulting it live publishes content the owner never chose to show."
    );
})->with('defaultDraftSections');

// The safety property that makes default-live acceptable for public_contact.
it('does not publish a default-live public_contact section that has no contact data', function () {
    $userId = seedSectionsFor('sec-live-empty-pc');

    $block = Block::query()
        ->where('user_id', $userId)
        ->where('block_group', Block::GROUP_SECTIONS)
        ->where('block_type', 'public_contact')
        ->first();

    // Live, but not enabled — the user has neither a public phone nor email, so
    // sectionEnvelope() resolves the wire key to null regardless of is_active.
    expect((bool) $block->is_active)->toBeTrue();
    expect((bool) $block->is_enabled)->toBeFalse(
        'A user with no public contact details must not have an ENABLED public_contact block — '.
        'is_active alone must never be able to publish an empty contact section.'
    );
});
