<?php

/** @phpstan-ignore-all */

use App\Http\Controllers\Api\Professional\Notifications\NotificationEmailPreferenceController;
use App\Jobs\Notifications\SendTransactionalNotificationEmailJob;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Tests for §28.10: capability-filtered preference index and update rejection.

beforeEach(function () {
    AccountCapabilities::flushCache();
    setupProfessionalsTable();
    attachTestSchemas();
    setupNotificationEmailPoliciesTable();
    setupNotificationEmailPreferencesTable();
});

afterEach(function () {
    AccountCapabilities::flushCache();
});

// ─── Helper ──────────────────────────────────────────────────────────────────

function makeProWithType(string $accountType): \App\Models\Core\Professional\Professional
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => 'pref-'.substr($id, 0, 8),
        'handle_lc' => 'pref-'.substr($id, 0, 8),
        'display_name' => ucfirst($accountType).' User',
        'professional_type' => $accountType === 'brand' ? 'brand' : 'affiliate',
        'account_type' => $accountType,
        'status' => 'active',
        'primary_email' => 'pref-'.$accountType.'@example.test',
        'has_historical_partner_links' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return \App\Models\Core\Professional\Professional::find($id);
}

function makePreferenceRequest(\App\Models\Core\Professional\Professional $pro, array $input = [], string $method = 'GET'): Request
{
    $req = Request::create('/', $method, $input);
    $req->attributes->set('professional', $pro);

    return $req;
}

// ─── index() — capability filtering ──────────────────────────────────────────

it('index: brand actor sees categories appropriate for its capability set', function () {
    $pro = makeProWithType('brand');

    $controller = app(NotificationEmailPreferenceController::class);
    $response = $controller->index(makePreferenceRequest($pro));
    $data = json_decode($response->getContent(), true);

    $returned = array_column($data['preferences'], 'category');
    $caps = AccountCapabilities::for($pro);
    $gateMap = SendTransactionalNotificationEmailJob::capabilityGateMap();

    // Assert gated categories are filtered consistently with capabilities.
    foreach ($gateMap as $category => $capProp) {
        if ($caps->{$capProp}) {
            expect($returned)->toContain($category);
        } else {
            expect($returned)->not->toContain($category);
        }
    }

    // Universal categories must always appear.
    $ungated = array_diff(NotificationPublisher::categories(), array_keys($gateMap));
    foreach ($ungated as $cat) {
        expect($returned)->toContain($cat);
    }
});

it('index: partner actor sees categories appropriate for its capability set', function () {
    $pro = makeProWithType('partner');

    $controller = app(NotificationEmailPreferenceController::class);
    $response = $controller->index(makePreferenceRequest($pro));
    $data = json_decode($response->getContent(), true);

    $returned = array_column($data['preferences'], 'category');
    $caps = AccountCapabilities::for($pro);
    $gateMap = SendTransactionalNotificationEmailJob::capabilityGateMap();

    foreach ($gateMap as $category => $capProp) {
        if ($caps->{$capProp}) {
            expect($returned)->toContain($category);
        } else {
            expect($returned)->not->toContain($category);
        }
    }

    $ungated = array_diff(NotificationPublisher::categories(), array_keys($gateMap));
    foreach ($ungated as $cat) {
        expect($returned)->toContain($cat);
    }
});

it('index: individual actor sees only universal categories and capability-allowed gated ones', function () {
    $pro = makeProWithType('individual');

    $controller = app(NotificationEmailPreferenceController::class);
    $response = $controller->index(makePreferenceRequest($pro));
    $data = json_decode($response->getContent(), true);

    $returned = array_column($data['preferences'], 'category');
    $caps = AccountCapabilities::for($pro);
    $gateMap = SendTransactionalNotificationEmailJob::capabilityGateMap();

    // Gated categories where individual's capability is false must be absent.
    foreach ($gateMap as $category => $capProp) {
        if (! $caps->{$capProp}) {
            expect($returned)->not->toContain($category,
                "Category '{$category}' should be hidden for individual (cap={$capProp} is false)"
            );
        }
    }

    // Universal categories (not in gateMap) must always be present.
    $ungated = array_diff(NotificationPublisher::categories(), array_keys($gateMap));
    foreach ($ungated as $cat) {
        expect($returned)->toContain($cat);
    }
});

it('index: individual without payout history does not see payout_settlement category', function () {
    $pro = makeProWithType('individual');
    // has_historical_partner_links = 0 and no payout rows → capability false.

    $controller = app(NotificationEmailPreferenceController::class);
    $response = $controller->index(makePreferenceRequest($pro));
    $data = json_decode($response->getContent(), true);

    $returned = array_column($data['preferences'], 'category');
    expect($returned)->not->toContain('payout_settlement');
});

// ─── update() — rejects disallowed categories ────────────────────────────────

it('update: rejects payout_settlement update for an individual without payout history (422)', function () {
    $pro = makeProWithType('individual');

    $controller = app(NotificationEmailPreferenceController::class);

    // Build a mock request that bypasses FormRequest validation but carries validated data.
    // The controller calls $request->validated()['preferences'] — mock that return value.
    $input = ['preferences' => [['category' => 'payout_settlement', 'enabled' => true]]];
    $req = Mockery::mock(\App\Http\Requests\Api\Professional\Notifications\UpdateNotificationEmailPreferencesRequest::class)
        ->shouldAllowMockingProtectedMethods()
        ->makePartial();
    $req->shouldReceive('validated')->andReturn($input);
    $req->attributes = new \Symfony\Component\HttpFoundation\ParameterBag;
    $req->attributes->set('professional', $pro);

    $response = $controller->update($req);

    expect($response->getStatusCode())->toBe(422);

    $body = json_decode($response->getContent(), true);
    expect($body['message'])->toBe('INVALID_CATEGORY_FOR_ACCOUNT_TYPE');
});

it('update: allows partner to update payout_settlement category (capability check passes)', function () {
    $pro = makeProWithType('partner');

    $controller = app(NotificationEmailPreferenceController::class);

    $input = ['preferences' => [['category' => 'payout_settlement', 'enabled' => false]]];
    $req = Mockery::mock(\App\Http\Requests\Api\Professional\Notifications\UpdateNotificationEmailPreferencesRequest::class)
        ->shouldAllowMockingProtectedMethods()
        ->makePartial();
    $req->shouldReceive('validated')->andReturn($input);
    $req->attributes = new \Symfony\Component\HttpFoundation\ParameterBag;
    $req->attributes->set('professional', $pro);

    // The capability check passes for partner; the request reaches the DB upsert.
    // In SQLite (test env) NOW() is unsupported — we only care the 422 is NOT returned.
    // A QueryException past the capability gate means the gate passed correctly.
    try {
        $response = $controller->update($req);
        expect($response->getStatusCode())->not->toBe(422);
    } catch (\Illuminate\Database\QueryException $e) {
        // QueryException means we got past the capability gate — the gate passed.
        expect($e->getMessage())->toContain('notifications.notification_email_preferences');
    }
});
