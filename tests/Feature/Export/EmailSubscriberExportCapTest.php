<?php

use App\Http\Controllers\Api\Staff\StaffSite\StaffEmailSubscriberController;
use App\Http\Controllers\Api\User\Notifications\UserEmailSubscriptionController;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Builds a Request carrying an admin partna_staff attribute — export() is admin-gated (#PRIV-2). */
function exportCapTest_adminRequest(): Request
{
    $request = Request::create('/', 'GET');
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $request->attributes->set('partna_staff', $staff);

    return $request;
}

beforeEach(function () {
    setupUsersTable();
    setupEmailSubscriptionsTable();
});

function makeExportPro(): User
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => 'exp-'.substr($id, 0, 8),
        'handle_lc' => 'exp-'.substr($id, 0, 8),
        'display_name' => 'Export Pro',
        'first_name' => 'Export',
        'primary_email' => 'exp-'.substr($id, 0, 8).'@example.com',
        'status' => 'active',
    ]);

    return User::query()->find($id);
}

function seedSub(string $proId, string $email): void
{
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'list_key' => 'marketing',
        'email' => $email,
        'email_lc' => $email,
        'full_name' => 'Fan',
        'status' => 'subscribed',
        'subscribed_at' => now()->toDateTimeString(),
        'unsubscribe_token' => Str::random(16),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function streamCsv($response): string
{
    ob_start();
    $response->sendContent();

    return ob_get_clean();
}

it('caps the staff export at the configured row limit and flags truncation', function () {
    config(['partna.export.max_rows' => 2]);
    $pro = makeExportPro();
    // Ordered by email: a, b stream; c is past the cap.
    seedSub($pro->id, 'a@example.com');
    seedSub($pro->id, 'b@example.com');
    seedSub($pro->id, 'c@example.com');

    $response = (new StaffEmailSubscriberController)->export(exportCapTest_adminRequest(), $pro);
    $csv = streamCsv($response);

    expect($response->headers->get('X-Export-Truncated'))->toBe('1')
        ->and($csv)->toContain('a@example.com')
        ->and($csv)->toContain('b@example.com')
        ->and($csv)->not->toContain('c@example.com');
});

it('does not flag truncation when the staff export fits under the cap', function () {
    config(['partna.export.max_rows' => 5]);
    $pro = makeExportPro();
    seedSub($pro->id, 'a@example.com');
    seedSub($pro->id, 'b@example.com');

    $response = (new StaffEmailSubscriberController)->export(exportCapTest_adminRequest(), $pro);
    $csv = streamCsv($response);

    expect($response->headers->get('X-Export-Truncated'))->toBeNull()
        ->and($csv)->toContain('a@example.com')
        ->and($csv)->toContain('b@example.com');
});

it('caps the user export at the configured row limit and flags truncation', function () {
    config(['partna.export.max_rows' => 2]);
    $pro = makeExportPro();
    seedSub($pro->id, 'a@example.com');
    seedSub($pro->id, 'b@example.com');
    seedSub($pro->id, 'c@example.com');

    $request = Request::create('/', 'GET');
    $request->attributes->set('professional', $pro); // current.pro middleware stand-in

    $response = (new UserEmailSubscriptionController)->export($request);
    $csv = streamCsv($response);

    expect($response->headers->get('X-Export-Truncated'))->toBe('1')
        ->and($csv)->toContain('a@example.com')
        ->and($csv)->not->toContain('c@example.com');
});
