<?php

use App\Http\Controllers\Api\User\SiteManagement\UserServiceController;
use App\Models\Core\User\Service;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();

    // square_variation_id is included because the controller adds whereNull('square_variation_id')
    // when the 'square' query param is absent. title matches the production column name on the model.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.services (
        id TEXT PRIMARY KEY,
        user_id TEXT,
        title TEXT,
        square_variation_id TEXT,
        duration_minutes INTEGER,
        price_cents INTEGER,
        sort_order INTEGER,
        is_active INTEGER,
        deleted_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

it('service destroy refuses a service belonging to another professional', function () {
    [$a, $b] = createTwoTenants('affiliate');
    $now = now()->toDateTimeString();

    $serviceId = (string) Str::uuid();
    DB::table('site.services')->insert([
        'id' => $serviceId,
        'user_id' => $a->id,
        'title' => 'Secret Cut',
        'price_cents' => 50_00,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $req = tenantRequestAs($b, [], 'DELETE');
    $service = Service::query()->findOrFail($serviceId);

    // Policy denies access with AuthorizationException (404 status via denyAsNotFound).
    expect(fn () => app(UserServiceController::class)->destroy($req, $service))
        ->toThrow(AuthorizationException::class);

    // Service must still exist.
    expect(DB::table('site.services')->where('id', $serviceId)->exists())->toBeTrue();
});

it('service index only returns services belonging to the authenticated professional', function () {
    [$a, $b] = createTwoTenants('affiliate');
    $now = now()->toDateTimeString();

    DB::table('site.services')->insert([
        ['id' => (string) Str::uuid(), 'user_id' => $a->id, 'title' => 'A Service', 'price_cents' => 100_00, 'created_at' => $now, 'updated_at' => $now],
        ['id' => (string) Str::uuid(), 'user_id' => $b->id, 'title' => 'B Service', 'price_cents' => 200_00, 'created_at' => $now, 'updated_at' => $now],
    ]);

    // flat=1 returns {services:[...]} and skips the ServiceCategory grouping query.
    $req = tenantRequestAs($b);
    $req->query->set('flat', '1');
    $response = app(UserServiceController::class)->index($req);
    $payload = $response->getData(true);

    $titles = collect($payload['services'] ?? [])->pluck('title')->all();
    expect($titles)->toContain('B Service');
    expect($titles)->not->toContain('A Service');
});
