<?php

use App\Http\Controllers\Api\User\Customers\UserEnquiryController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Opt in to the full Laravel bootstrap — the Pest.php default only binds
// TestCase for tests/Feature; this unit test exercises the real controller
// + DB, so it needs facades resolved.
uses(TestCase::class)->in(__FILE__);

// setupContactInboxSchema, makeInboxUser, seedInboxEnquiry, requestAs are
// provided globally by tests/Helpers/EnquiryInboxTestHelpers.php (loaded
// via Pest.php). No local re-declarations needed.

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();
});

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
        'first_name' => 'Other',
        'primary_email' => 'other@e.com',
        'status' => 'active',
    ]);
    seedInboxEnquiry($otherId, (string) Str::uuid(), ['name' => 'Not mine']);

    $response = app(UserEnquiryController::class)->index(requestAs($me));
    $body = $response->getData(true);

    expect($body['data'])->toHaveCount(0);
});

// AUDIT-2026-08-05: update()/destroy() (PATCH/DELETE /api/enquiries/{id}) were
// removed as orphaned endpoints — this file's index()-only coverage is
// unaffected; markRead/markReplied/archive/restore/markSpam keep their own
// coverage in EnquiryInboxControllerTest.php and the tenant-isolation suites.
