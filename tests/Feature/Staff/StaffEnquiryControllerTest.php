<?php

use App\Http\Controllers\Api\Staff\StaffSite\StaffEnquiryController;
use App\Models\Core\Professional\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
    attachTestSchemas();
    // site.enquiries is created in ProfessionalEnquiryControllerTest's setupContactInboxSchema().
    // Inline here so this test doesn't depend on the Pest discovery order.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.enquiries (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
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
});

function makeStaffEnquiryProfessional(): User
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => 'enq-'.substr($id, 0, 8),
        'handle_lc' => 'enq-'.substr($id, 0, 8),
        'display_name' => 'Enq Pro',
        'primary_email' => 'enq-'.substr($id, 0, 8).'@example.com',
        'status' => 'active',
    ]);

    return User::query()->find($id);
}

function seedStaffEnquiry(string $proId, array $overrides = []): void
{
    DB::connection('pgsql')->table('site.enquiries')->insert(array_merge([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'site_id' => (string) Str::uuid(),
        'name' => 'Visitor',
        'email' => 'v@example.com',
        'subject' => 'Hello',
        'message' => 'A ten-char message here.',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $overrides));
}

it('returns newest-first enquiries scoped to the route-bound professional', function () {
    $pro = makeStaffEnquiryProfessional();
    $otherPro = makeStaffEnquiryProfessional();

    seedStaffEnquiry($pro->id, ['name' => 'Older', 'created_at' => now()->subDay()->toDateTimeString()]);
    seedStaffEnquiry($pro->id, ['name' => 'Newer']);
    seedStaffEnquiry($otherPro->id, ['name' => 'Not mine']);

    $controller = new StaffEnquiryController;
    $response = $controller->index(Request::create('/', 'GET'), $pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['data'])->toHaveCount(2)
        ->and($body['data'][0]['name'])->toBe('Newer')
        ->and($body['data'][1]['name'])->toBe('Older')
        ->and($body['meta']['total'])->toBe(2);
});

it('respects per_page when provided', function () {
    $pro = makeStaffEnquiryProfessional();

    for ($i = 0; $i < 5; $i++) {
        seedStaffEnquiry($pro->id, ['name' => "Visitor {$i}"]);
    }

    $controller = new StaffEnquiryController;
    $response = $controller->index(Request::create('/?per_page=2', 'GET'), $pro);
    $body = $response->getData(true);

    expect($body['data'])->toHaveCount(2)
        ->and($body['meta']['per_page'])->toBe(2)
        ->and($body['meta']['total'])->toBe(5);
});
