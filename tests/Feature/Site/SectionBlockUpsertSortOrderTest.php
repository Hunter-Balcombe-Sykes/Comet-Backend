<?php

use App\Http\Controllers\Api\User\SiteManagement\UserSectionBlockController;
use App\Http\Requests\Api\User\Site\UpsertSectionBlockRequest;
use App\Models\Core\User\User;
use App\Services\Site\ReorderService;
use App\Services\User\SectionVisibilityService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Regression guard for SEM-8: the new-block branch of upsert() must use
 * max(sort_order)+1, not count(), to stay gap-safe against the partial
 * unique index on (site_id, block_group, sort_order) WHERE block_group =
 * 'sections'.
 *
 * count() collides whenever a hard-deleted row leaves a gap in sort_order
 * values. E.g. rows at 0 and 1, row 0 deleted → count()=1 collides with
 * the live row at 1; max()+1=2 is safe.
 *
 * Reachability: upsert() calls syncAllowedSections() before firstOrNew(),
 * so in normal single-threaded flow syncAllowedSections() creates the target
 * block first and firstOrNew() always finds an existing row. The bug-affected
 * new-block branch (`!$block->exists`) is only reachable via concurrent
 * requests in production. To isolate it in tests, syncAllowedSections() is
 * changed from private to protected (zero runtime impact) so a test subclass
 * can stub it out, letting firstOrNew() encounter a truly absent row.
 */
beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    shimPgAdvisoryLockForSqlite();
});

// ──────────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Seed a section block row directly for the given professional, bypassing
 * syncAllowedSections(). Returns the inserted row id.
 * Mirrors seedSectionBlock() in SectionReorderTest.
 */
function seedSectionBlockForUpsertTest(User $pro, string $blockType, int $sortOrder): string
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::table('site.blocks')->insert([
        'id' => $id,
        'user_id' => $pro->id,
        'site_id' => $pro->site->id,
        'block_group' => 'sections',
        'block_type' => $blockType,
        'sort_order' => $sortOrder,
        'is_active' => 0,
        'is_enabled' => 1,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

/**
 * Build a controller instance whose syncAllowedSections() is a no-op.
 * This lets firstOrNew() inside upsert()'s transaction encounter a truly
 * absent block, exercising the new-block branch where the count() bug lived.
 *
 * syncAllowedSections() must be protected (not private) for this to work —
 * PHP private methods are non-virtual so subclasses cannot shadow them.
 */
function makeSynclessController(): UserSectionBlockController
{
    return new class(app(SectionVisibilityService::class), app(ReorderService::class)) extends UserSectionBlockController
    {
        protected function syncAllowedSections(string $userId, string $siteId, array $allowedSections): Collection
        {
            // Intentional no-op: lets the new-block branch inside upsert()'s
            // transaction run with no block pre-created by the sync phase.
            return new Collection;
        }
    };
}

/**
 * Call upsert() via the controller, setting the professional attribute on the
 * request as current.pro middleware would. Mirrors callSectionReorder() from
 * SectionReorderTest. Uses the syncless controller by default so the new-block
 * branch inside the transaction is exercisable.
 */
function callUpsert(User $pro, string $blockType, array $extra = [], ?UserSectionBlockController $controller = null)
{
    $payload = array_merge(['block_type' => $blockType, 'publication_state' => 'draft'], $extra);
    $plain = tenantRequestAs($pro, $payload, 'PUT');
    $req = UpsertSectionBlockRequest::createFrom($plain);
    $req->setContainer(app())->setRedirector(app('redirect'));
    $req->validateResolved();
    $req->attributes->set('professional', $pro);

    $ctrl = $controller ?? makeSynclessController();

    return $ctrl->upsert($req, $blockType);
}

// ──────────────────────────────────────────────────────────────────────────────
// Tests
// ──────────────────────────────────────────────────────────────────────────────

it('gap-safe regression (SEM-8): upsert assigns sort_order 2 not 1 after a hard-delete gap', function () {
    // Allow any section type in the upsert() allowlist check.
    Config::set('partna.section_block_types', ['services', 'newsletter', 'contacts_collection']);

    $pro = createBrandTenant('upsert-gap-a');
    $siteId = $pro->site->id;

    // Seed TWO section blocks: services at sort_order 0, newsletter at sort_order 1.
    seedSectionBlockForUpsertTest($pro, 'services', 0);
    seedSectionBlockForUpsertTest($pro, 'newsletter', 1);

    // Hard-delete the row at sort_order 0, leaving one live row at sort_order 1.
    // State after delete: count()=1, max()=1.
    // Bug: count() → sort_order=1 → COLLIDES with the live newsletter row.
    // Fix: max()+1 → sort_order=2 → gap-safe.
    DB::table('site.blocks')
        ->where('user_id', $pro->id)
        ->where('site_id', $siteId)
        ->where('block_type', 'services')
        ->delete();

    // Upsert a third, not-yet-existing section type via the syncless controller.
    // syncAllowedSections() is stubbed to a no-op, so firstOrNew() sees no existing
    // contacts_collection row and the new-block branch runs.
    callUpsert($pro, 'contacts_collection');

    $sortOrder = (int) DB::table('site.blocks')
        ->where('user_id', $pro->id)
        ->where('site_id', $siteId)
        ->where('block_type', 'contacts_collection')
        ->value('sort_order');

    // Must be 2 (max+1 of the live row at 1), NOT 1 (count() collision).
    expect($sortOrder)->toBe(2);
});

it('empty-set semantics: first block on a fresh tenant gets sort_order 0', function () {
    // With no existing blocks, max() returns null → ?? -1 → +1 → 0.
    // Verifies the null-coalescing guard works end-to-end through the controller.
    Config::set('partna.section_block_types', ['newsletter']);

    $pro = createBrandTenant('upsert-empty-a');
    $siteId = $pro->site->id;

    callUpsert($pro, 'newsletter');

    $sortOrder = (int) DB::table('site.blocks')
        ->where('user_id', $pro->id)
        ->where('site_id', $siteId)
        ->where('block_type', 'newsletter')
        ->value('sort_order');

    // First block on an empty site must be 0.
    expect($sortOrder)->toBe(0);
});
