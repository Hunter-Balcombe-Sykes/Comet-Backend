<?php

use App\Http\Controllers\Api\Staff\StaffSite\StaffEmailSubscriberController;
use App\Models\Core\Professional\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
    setupEmailSubscriptionsTable();
});

function makeStaffSubscriberProfessional(): User
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => 'sub-'.substr($id, 0, 8),
        'handle_lc' => 'sub-'.substr($id, 0, 8),
        'display_name' => 'Subs Pro',
        'primary_email' => 'sub-'.substr($id, 0, 8).'@example.com',
        'status' => 'active',
    ]);

    return User::query()->find($id);
}

function seedSubscription(string $proId, array $overrides = []): void
{
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert(array_merge([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'list_key' => 'marketing',
        'email' => 'fan@example.com',
        'email_lc' => 'fan@example.com',
        'full_name' => 'Fan',
        'status' => 'subscribed',
        'subscribed_at' => now()->toDateTimeString(),
        'unsubscribe_token' => Str::random(16),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $overrides));
}

it('lists subscribers for the route-bound professional only', function () {
    $pro = makeStaffSubscriberProfessional();
    $otherPro = makeStaffSubscriberProfessional();

    seedSubscription($pro->id, ['email' => 'mine@example.com', 'email_lc' => 'mine@example.com']);
    seedSubscription($otherPro->id, ['email' => 'theirs@example.com', 'email_lc' => 'theirs@example.com']);

    $controller = new StaffEmailSubscriberController;
    $response = $controller->index(Request::create('/', 'GET'), $pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['subscriptions'])->toHaveCount(1)
        ->and($body['subscriptions'][0]['email'])->toBe('mine@example.com')
        ->and($body['filters']['list_key'])->toBe('marketing');
});

it('filters by status when ?status=unsubscribed is provided', function () {
    $pro = makeStaffSubscriberProfessional();

    seedSubscription($pro->id, ['email' => 'in@example.com', 'email_lc' => 'in@example.com', 'status' => 'subscribed']);
    seedSubscription($pro->id, ['email' => 'out@example.com', 'email_lc' => 'out@example.com', 'status' => 'unsubscribed', 'unsubscribed_at' => now()->toDateTimeString()]);

    $controller = new StaffEmailSubscriberController;
    $response = $controller->index(Request::create('/?status=unsubscribed', 'GET'), $pro);
    $body = $response->getData(true);

    expect($body['subscriptions'])->toHaveCount(1)
        ->and($body['subscriptions'][0]['status'])->toBe('unsubscribed');
});

it('streams a CSV export with the canonical header row', function () {
    $pro = makeStaffSubscriberProfessional();
    seedSubscription($pro->id, ['email' => 'csv@example.com', 'email_lc' => 'csv@example.com', 'full_name' => 'CSV Person']);

    $controller = new StaffEmailSubscriberController;
    $response = $controller->export(Request::create('/', 'GET'), $pro);

    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($csv)->toContain('email,full_name,status,subscribed_at,unsubscribed_at')
        ->and($csv)->toContain('csv@example.com')
        ->and($csv)->toContain('CSV Person');
});
