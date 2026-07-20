<?php

/**
 * P3-32: StaffServiceManagementController::index() must cap the services query at
 * limit(500) and the categories query at limit(200) — same caps as the user-facing
 * twins — so that a user with an unusually large catalogue cannot cause an unbounded
 * DB read through the staff endpoint.
 */

use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffServiceManagementController;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Builds a Request carrying a partna_staff attribute — #SEC-5 gates index(). */
function staffServiceIndexTest_request(string $uri): Request
{
    $request = Request::create($uri, 'GET');
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $request->attributes->set('partna_staff', $staff);

    return $request;
}

beforeEach(function () {
    setupUsersTable();
    setupServicesTable(); // also sets up site.service_categories
});

function makeStaffServicePro(): User
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => 'svc-'.substr($id, 0, 8),
        'handle_lc' => 'svc-'.substr($id, 0, 8),
        'display_name' => 'Service Pro',
        'primary_email' => 'svc-'.substr($id, 0, 8).'@example.com',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return User::query()->findOrFail($id);
}

function bulkSeedServices(string $userId, int $count): void
{
    $now = now()->toDateTimeString();
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'title' => "Service {$i}",
            'price_cents' => 1000,
            'currency_code' => 'AUD',
            'is_active' => 1,
            'sort_order' => $i,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    // Chunked insert to avoid SQLite variable limit per statement.
    foreach (array_chunk($rows, 50) as $chunk) {
        DB::connection('pgsql')->table('site.services')->insert($chunk);
    }
}

function bulkSeedServiceCategories(string $userId, int $count): void
{
    $now = now()->toDateTimeString();
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'title' => "Category {$i}",
            'sort_order' => $i,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    foreach (array_chunk($rows, 50) as $chunk) {
        DB::connection('pgsql')->table('site.service_categories')->insert($chunk);
    }
}

it('caps the flat services list at 500 even when more than 500 exist', function () {
    $pro = makeStaffServicePro();
    bulkSeedServices($pro->id, 510);

    $controller = new StaffServiceManagementController;
    $response = $controller->index(staffServiceIndexTest_request('/'), $pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200);
    // The cap is 500 — 510 seeded, 500 should be returned.
    // success() wraps directly: body = ['services' => [...], 'filters' => [...]].
    expect($body['services'])->toHaveCount(500);
});

it('caps the grouped services list at 500 even when more than 500 exist', function () {
    $pro = makeStaffServicePro();
    bulkSeedServices($pro->id, 510);

    $controller = new StaffServiceManagementController;
    // Pass grouped as a query parameter so $request->boolean('grouped') resolves to true.
    $request = Request::create('/', 'GET', ['grouped' => '1']);
    $request->attributes->set('partna_staff', tap(new PartnaStaff, fn ($s) => $s->role = PartnaStaff::ROLE_ADMIN));
    $response = $controller->index($request, $pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200);

    // success() wraps directly: body = ['categories' => [...], 'uncategorised_services' => [...], 'filters' => [...]].
    // All services are uncategorised (no category_id). Count them across both buckets.
    $uncategorisedCount = count($body['uncategorised_services'] ?? []);
    $categorisedCount = collect($body['categories'] ?? [])->sum(fn ($cat) => count($cat['services']));

    expect($uncategorisedCount + $categorisedCount)->toBe(500);
});

it('caps the grouped categories list at 200 even when more than 200 exist', function () {
    $pro = makeStaffServicePro();
    bulkSeedServiceCategories($pro->id, 210);

    $controller = new StaffServiceManagementController;
    $request = Request::create('/', 'GET', ['grouped' => '1']);
    $request->attributes->set('partna_staff', tap(new PartnaStaff, fn ($s) => $s->role = PartnaStaff::ROLE_ADMIN));
    $response = $controller->index($request, $pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200);
    // success() wraps directly: body = ['categories' => [...], ...].
    expect($body['categories'])->toHaveCount(200);
});
