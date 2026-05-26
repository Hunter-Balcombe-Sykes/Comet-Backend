<?php

use App\Http\Controllers\Api\User\Customers\UserEnquiryController;
use App\Models\Core\User\User;
use App\Models\Core\Site\Enquiry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Opt in to the full Laravel bootstrap — the Pest.php default only binds
// TestCase for tests/Feature; this unit test exercises the real controller
// + DB, so it needs facades resolved.
uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();
});

function setupContactInboxSchema(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.enquiries (
        id TEXT PRIMARY KEY,
        user_id TEXT NOT NULL,
        site_id TEXT NOT NULL,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        phone TEXT NULL,
        subject TEXT NOT NULL,
        message TEXT NOT NULL,
        ip_hash TEXT NULL,
        user_agent TEXT NULL,
        read_at TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
}

function makeInboxUser(): User
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => 'inbox-'.substr($id, 0, 8),
        'handle_lc' => 'inbox-'.substr($id, 0, 8),
        'display_name' => 'Inbox Pro',
        'primary_email' => 'inbox-'.substr($id, 0, 8).'@example.com',
        'status' => 'active',
    ]);

    return User::query()->find($id);
}

function seedInboxEnquiry(string $proId, string $siteId, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.enquiries')->insert(array_merge([
        'id' => $id,
        'user_id' => $proId,
        'site_id' => $siteId,
        'name' => 'Sarah',
        'email' => 's@e.com',
        'subject' => 'Press',
        'message' => 'A ten-char message here.',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $overrides));

    return $id;
}

function requestAs(User $pro, string $method = 'GET', array $data = []): Request
{
    // Bypass the current.pro middleware by populating the attribute directly.
    // Mirrors how the middleware would set it after JWT + pro lookup.
    $request = Request::create('/api/professional/enquiries', $method, $data);
    $request->attributes->set('professional', $pro);

    return $request;
}

it('lists the current professional enquiries newest first', function () {
    $pro = makeInboxUser();
    $siteId = (string) Str::uuid();

    seedInboxEnquiry($pro->id, $siteId, ['name' => 'Older', 'created_at' => now()->subDay()->toDateTimeString()]);
    seedInboxEnquiry($pro->id, $siteId, ['name' => 'Newer']);

    $response = app(UserEnquiryController::class)->index(requestAs($pro));
    $body = $response->getData(true);

    expect($body['data'][0]['name'])->toBe('Newer');
    expect($body['data'][1]['name'])->toBe('Older');
});

it('does not leak other professionals enquiries', function () {
    $me = makeInboxUser();

    $otherId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $otherId,
        'handle' => 'other',
        'handle_lc' => 'other',
        'display_name' => 'Other',
        'primary_email' => 'other@e.com',
        'status' => 'active',
    ]);
    seedInboxEnquiry($otherId, (string) Str::uuid(), ['name' => 'Not mine']);

    $response = app(UserEnquiryController::class)->index(requestAs($me));
    $body = $response->getData(true);

    expect($body['data'])->toHaveCount(0);
});

it('marks an enquiry as read', function () {
    $pro = makeInboxUser();
    $enquiryId = seedInboxEnquiry($pro->id, (string) Str::uuid());

    app(UserEnquiryController::class)->update(requestAs($pro, 'PATCH', ['read' => true]), $enquiryId);

    $fresh = Enquiry::query()->find($enquiryId);
    expect($fresh->read_at)->not->toBeNull();
});

it('marks an enquiry as unread', function () {
    $pro = makeInboxUser();
    $enquiryId = seedInboxEnquiry($pro->id, (string) Str::uuid(), ['read_at' => now()->toDateTimeString()]);

    app(UserEnquiryController::class)->update(requestAs($pro, 'PATCH', ['read' => false]), $enquiryId);

    $fresh = Enquiry::query()->find($enquiryId);
    expect($fresh->read_at)->toBeNull();
});

it('soft-deletes an enquiry', function () {
    $pro = makeInboxUser();
    $enquiryId = seedInboxEnquiry($pro->id, (string) Str::uuid());

    app(UserEnquiryController::class)->destroy(requestAs($pro, 'DELETE'), $enquiryId);

    expect(Enquiry::query()->find($enquiryId))->toBeNull();
    expect(Enquiry::withTrashed()->find($enquiryId))->not->toBeNull();
});

it('returns 404 when acting on another professionals enquiry', function () {
    $me = makeInboxUser();

    $otherId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $otherId,
        'handle' => 'other2',
        'handle_lc' => 'other2',
        'display_name' => 'Other 2',
        'primary_email' => 'other2@e.com',
        'status' => 'active',
    ]);
    $enquiryId = seedInboxEnquiry($otherId, (string) Str::uuid());

    $response = app(UserEnquiryController::class)->update(requestAs($me, 'PATCH', ['read' => true]), $enquiryId);
    expect($response->getStatusCode())->toBe(404);
});
