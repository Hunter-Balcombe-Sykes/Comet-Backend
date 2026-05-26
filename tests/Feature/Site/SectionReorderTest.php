<?php

use App\Http\Controllers\Api\User\SiteManagement\UserSectionBlockController;
use App\Http\Requests\Api\User\Site\ReorderBlocksRequest;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Self-service reorder for the section blocks group on a professional's
 * mini-site. Mirrors the existing staff endpoint, scoped to the authenticated
 * professional and their site.
 */
beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    shimPgAdvisoryLockForSqlite();
});

function seedSectionBlock(User $pro, string $blockType, int $sortOrder): string
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
        'is_active' => 1,
        'is_enabled' => 1,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

function callSectionReorder(User $pro, array $ids)
{
    $plain = tenantRequestAs($pro, ['ids' => $ids], 'POST');
    $req = ReorderBlocksRequest::createFrom($plain);
    $req->setContainer(app())->setRedirector(app('redirect'));
    $req->validateResolved();
    $req->attributes->set('professional', $pro);

    return app(UserSectionBlockController::class)->reorder($req);
}

it('reorders sections owned by the authenticated professional', function () {
    $pro = createBrandTenant('reorder-a');
    $gallery = seedSectionBlock($pro, 'gallery', 0);
    $shop = seedSectionBlock($pro, 'shop', 1);
    $bio = seedSectionBlock($pro, 'bio', 2);

    callSectionReorder($pro, [$bio, $gallery, $shop]);

    expect((int) DB::table('site.blocks')->where('id', $bio)->value('sort_order'))->toBe(0);
    expect((int) DB::table('site.blocks')->where('id', $gallery)->value('sort_order'))->toBe(1);
    expect((int) DB::table('site.blocks')->where('id', $shop)->value('sort_order'))->toBe(2);
});

it('preserves sections not in the ids list at the end of the order', function () {
    $pro = createBrandTenant('reorder-b');
    $gallery = seedSectionBlock($pro, 'gallery', 0);
    $shop = seedSectionBlock($pro, 'shop', 1);
    $bio = seedSectionBlock($pro, 'bio', 2);
    $contact = seedSectionBlock($pro, 'contact', 3);

    // Only specify two ids — the others must follow in their original order.
    callSectionReorder($pro, [$bio, $gallery]);

    expect((int) DB::table('site.blocks')->where('id', $bio)->value('sort_order'))->toBe(0);
    expect((int) DB::table('site.blocks')->where('id', $gallery)->value('sort_order'))->toBe(1);
    expect((int) DB::table('site.blocks')->where('id', $shop)->value('sort_order'))->toBe(2);
    expect((int) DB::table('site.blocks')->where('id', $contact)->value('sort_order'))->toBe(3);
});

it('refuses to reorder a section belonging to another professional', function () {
    [$a, $b] = createTwoTenants('brand');
    seedSectionBlock($a, 'gallery', 0);
    $bSection = seedSectionBlock($b, 'gallery', 0);

    expect(fn () => callSectionReorder($a, [$bSection]))
        ->toThrow(HttpException::class);

    // Tenant B's section must remain untouched.
    expect((int) DB::table('site.blocks')->where('id', $bSection)->value('sort_order'))->toBe(0);
});

it('ignores link blocks when reordering sections', function () {
    $pro = createBrandTenant('reorder-c');
    $gallery = seedSectionBlock($pro, 'gallery', 0);
    $shop = seedSectionBlock($pro, 'shop', 1);

    // A link in a different block_group must NOT be eligible for the section
    // reorder — supplying its id is treated as a foreign id.
    $linkId = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::table('site.blocks')->insert([
        'id' => $linkId,
        'user_id' => $pro->id,
        'site_id' => $pro->site->id,
        'block_group' => 'links',
        'block_type' => 'link',
        'sort_order' => 0,
        'is_active' => 1,
        'is_enabled' => 1,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    expect(fn () => callSectionReorder($pro, [$linkId, $gallery, $shop]))
        ->toThrow(HttpException::class);
});
