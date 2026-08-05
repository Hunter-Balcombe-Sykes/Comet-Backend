<?php

use App\Http\Controllers\Api\User\Customers\UserCustomerController;
use App\Http\Controllers\Api\User\Customers\UserEnquiryController;
use App\Models\Core\User\Customer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.customers (
        id TEXT PRIMARY KEY,
        user_id TEXT,
        email TEXT,
        phone TEXT,
        full_name TEXT,
        source TEXT,
        marketing_opt_in_cached INTEGER,
        deleted_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.enquiries (
        id TEXT PRIMARY KEY,
        user_id TEXT,
        email TEXT,
        name TEXT,
        message TEXT,
        read_at TEXT,
        deleted_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

it('customer index never includes customers from another professional', function () {
    [$a, $b] = createTwoTenants();
    $now = now()->toDateTimeString();

    DB::table('site.customers')->insert([
        ['id' => (string) Str::uuid(), 'user_id' => $a->id, 'email' => 'a@x.com', 'full_name' => 'A Customer', 'created_at' => $now, 'updated_at' => $now],
        ['id' => (string) Str::uuid(), 'user_id' => $b->id, 'email' => 'b@x.com', 'full_name' => 'B Customer', 'created_at' => $now, 'updated_at' => $now],
    ]);

    $req = tenantRequestAs($b);
    $response = app(UserCustomerController::class)->index($req);
    $payload = $response->getData(true);

    // success() wraps via response()->json($payload) — no additional 'data' envelope.
    $emails = collect($payload['customers'] ?? [])->pluck('email')->all();
    expect($emails)->toContain('b@x.com');
    expect($emails)->not->toContain('a@x.com');
});

it('customer show refuses a customer belonging to another professional', function () {
    [$a, $b] = createTwoTenants();
    $now = now()->toDateTimeString();

    $customerId = (string) Str::uuid();
    DB::table('site.customers')->insert([
        'id' => $customerId,
        'user_id' => $a->id,
        'email' => 'secret@a.com',
        'full_name' => 'Secret A',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $req = tenantRequestAs($b);
    $customer = Customer::query()->findOrFail($customerId);

    // Policy now throws AuthorizationException (404) instead of abort_unless HttpException.
    try {
        app(UserCustomerController::class)->show($req, $customer);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }
});

// AUDIT-2026-08-05: UserEnquiryController::update() (PATCH /api/enquiries/{id})
// was removed as an orphaned endpoint — markRead() (POST /api/enquiries/{id}/read)
// is the surviving status-transition verb and exercises the same
// transition()-helper ownership gate.
it('enquiry markRead refuses an enquiry belonging to another professional', function () {
    [$a, $b] = createTwoTenants();
    $now = now()->toDateTimeString();

    $enqId = (string) Str::uuid();
    DB::table('site.enquiries')->insert([
        'id' => $enqId,
        'user_id' => $a->id,
        'email' => 'e@a.com',
        'message' => 'Hello',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $req = tenantRequestAs($b, [], 'POST');
    $response = app(UserEnquiryController::class)->markRead($req, $enqId);

    // transition() scopes by user_id and returns a 404 JsonResponse — Brand
    // B's query finds nothing.
    expect($response->getStatusCode())->toBe(404);

    // Original enquiry must be untouched.
    expect(DB::table('site.enquiries')->where('id', $enqId)->value('read_at'))->toBeNull();
});
