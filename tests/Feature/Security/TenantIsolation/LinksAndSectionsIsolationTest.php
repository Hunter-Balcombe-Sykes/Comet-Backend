<?php

use App\Http\Controllers\Api\User\SiteManagement\UserLinkBlockController;
use App\Http\Requests\Api\User\Site\IndexLinkBlockRequest;
use App\Http\Requests\Api\User\Site\UpdateLinkBlockRequest;
use App\Models\Core\Site\Block;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
});

it('link index only returns blocks belonging to the authenticated professional', function () {
    [$a, $b] = createTwoTenants('brand');
    $now = now()->toDateTimeString();

    DB::table('site.blocks')->insert([
        [
            'id' => (string) Str::uuid(),
            'user_id' => $a->id,
            'site_id' => $a->site->id,
            'block_group' => 'links',
            'block_type' => 'link',
            'title' => 'Secret A',
            'url' => 'https://a.example',
            'sort_order' => 0,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => (string) Str::uuid(),
            'user_id' => $b->id,
            'site_id' => $b->site->id,
            'block_group' => 'links',
            'block_type' => 'link',
            'title' => 'B Link',
            'url' => 'https://b.example',
            'sort_order' => 0,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    // IndexLinkBlockRequest has no validation rules — createFrom() is safe here.
    $plainReq = tenantRequestAs($b);
    $req = IndexLinkBlockRequest::createFrom($plainReq);
    $req->setContainer(app());
    $req->attributes->set('professional', $b);

    $response = app(UserLinkBlockController::class)->index($req);
    $payload = $response->getData(true);

    // success() wraps via response()->json($data) — no 'data' envelope.
    $titles = collect($payload['blocks'] ?? [])->pluck('title')->all();
    expect($titles)->toContain('B Link');
    expect($titles)->not->toContain('Secret A');
});

it('link update refuses a block belonging to another professional site', function () {
    [$a, $b] = createTwoTenants('brand');
    $now = now()->toDateTimeString();

    $blockId = (string) Str::uuid();
    DB::table('site.blocks')->insert([
        'id' => $blockId,
        'user_id' => $a->id,
        'site_id' => $a->site->id,
        'block_group' => 'links',
        'block_type' => 'link',
        'title' => 'Secret A',
        'url' => 'https://a.example',
        'sort_order' => 0,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Ownership is now enforced via SitePolicy — authorizeForUser throws AuthorizationException.
    // The policy check runs before validated(), so we must wire the route binding to get past
    // prepareForValidation's UUID extraction (authorizeForUser fires after type check, before validated()).
    $plainReq = tenantRequestAs($b, ['title' => 'Pwned', 'url' => 'https://pwned.example'], 'PATCH');
    $block = Block::query()->findOrFail($blockId);

    $formReq = UpdateLinkBlockRequest::createFrom($plainReq);
    $formReq->setRouteResolver(fn () => tap(new \Illuminate\Routing\Route('PATCH', '/', []), function ($route) use ($block) {
        $route->bind(request());
        $route->setParameter('linkBlock', $block);
    }));
    $formReq->setContainer(app());
    $formReq->validateResolved();

    try {
        app(UserLinkBlockController::class)->update($formReq, $block);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }

    // Confirm the block was not modified — the key isolation guarantee.
    expect(DB::table('site.blocks')->where('id', $blockId)->value('title'))->toBe('Secret A');
});
