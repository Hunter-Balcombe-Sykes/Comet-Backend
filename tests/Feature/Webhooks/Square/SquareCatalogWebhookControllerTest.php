<?php

use App\Jobs\Square\SyncSquareCatalogDeltaJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    Bus::fake();
    Cache::flush();
    setupProfessionalIntegrationsTable();

    Config::set('partna.features.square_sync', true);
    Config::set('services.square.webhook_signature_key', 'test-square-key');
    Config::set('services.square.webhook_notification_url', 'http://localhost/api/webhooks/square');
});

it('rejects with 401 when x-square-hmacsha256-signature is missing', function () {
    $payload = ['type' => 'catalog.version.updated', 'merchant_id' => 'merch-1', 'event_id' => 'evt-1'];

    $this->postJson('/api/webhooks/square', $payload, [])
        ->assertStatus(401);

    Bus::assertNotDispatched(SyncSquareCatalogDeltaJob::class);
});

it('rejects with 401 when signature does not match', function () {
    $payload = ['type' => 'catalog.version.updated', 'merchant_id' => 'merch-1', 'event_id' => 'evt-2'];

    $this->postJson('/api/webhooks/square', $payload, [
        'x-square-hmacsha256-signature' => 'bad-sig',
    ])->assertStatus(401);

    Bus::assertNotDispatched(SyncSquareCatalogDeltaJob::class);
});

it('accepts a valid signature and dispatches sync job for catalog.version.updated', function () {
    $payload = ['type' => 'catalog.version.updated', 'merchant_id' => 'merch-42', 'event_id' => 'evt-happy-1'];
    $body = json_encode($payload);
    $sig = signSquareBody('http://localhost/api/webhooks/square', $body, 'test-square-key');

    $this->postJson('/api/webhooks/square', $payload, [
        'x-square-hmacsha256-signature' => $sig,
    ])
        ->assertOk()
        ->assertJson(['received' => true, 'queued' => true]);

    Bus::assertDispatched(SyncSquareCatalogDeltaJob::class);
});

it('square — returns duplicate=true on second delivery of same event_id', function () {
    $payload = ['type' => 'catalog.version.updated', 'merchant_id' => 'merch-7', 'event_id' => 'evt-dup-sq-1'];
    $body = json_encode($payload);
    $sig = signSquareBody('http://localhost/api/webhooks/square', $body, 'test-square-key');

    $this->postJson('/api/webhooks/square', $payload, ['x-square-hmacsha256-signature' => $sig])
        ->assertOk()->assertJson(['queued' => true]);

    $this->postJson('/api/webhooks/square', $payload, ['x-square-hmacsha256-signature' => $sig])
        ->assertOk()->assertJson(['received' => true, 'duplicate' => true]);

    Bus::assertDispatchedTimes(SyncSquareCatalogDeltaJob::class, 1);
});

it('square — deletes integration on oauth.authorization.revoked', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SQUARE,
        'external_account_id' => 'merch-revoke',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = ['type' => 'oauth.authorization.revoked', 'merchant_id' => 'merch-revoke', 'event_id' => 'evt-revoke-sq-1'];
    $body = json_encode($payload);
    $sig = signSquareBody('http://localhost/api/webhooks/square', $body, 'test-square-key');

    $this->postJson('/api/webhooks/square', $payload, ['x-square-hmacsha256-signature' => $sig])
        ->assertOk()
        ->assertJson(['received' => true, 'revoked' => true]);

    expect(DB::table('core.professional_integrations')
        ->where('external_account_id', 'merch-revoke')->count())->toBe(0);
});

it('square — returns ignored=missing_merchant_id when merchant_id is absent', function () {
    $payload = ['type' => 'catalog.version.updated', 'event_id' => 'evt-no-merch-1'];
    $body = json_encode($payload);
    $sig = signSquareBody('http://localhost/api/webhooks/square', $body, 'test-square-key');

    $this->postJson('/api/webhooks/square', $payload, ['x-square-hmacsha256-signature' => $sig])
        ->assertOk()
        ->assertJson(['received' => true, 'ignored' => 'missing_merchant_id']);

    Bus::assertNotDispatched(SyncSquareCatalogDeltaJob::class);
});
