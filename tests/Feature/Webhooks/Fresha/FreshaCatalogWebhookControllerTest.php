<?php

use App\Jobs\Fresha\SyncFreshaCatalogDeltaJob;
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

    Config::set('partna.features.fresha_sync', true);
    Config::set('services.fresha.webhook_signature_key', 'test-fresha-key');
    Config::set('services.fresha.webhook_notification_url', 'http://localhost/api/webhooks/fresha');
});

it('rejects with 401 when x-fresha-signature header is missing', function () {
    $payload = ['type' => 'catalog.version.updated', 'business_id' => 'biz-1', 'event_id' => 'evt-1'];

    $this->postJson('/api/webhooks/fresha', $payload, [])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid Fresha webhook signature.']);

    Bus::assertNotDispatched(SyncFreshaCatalogDeltaJob::class);
});

it('rejects with 401 when signature does not match', function () {
    $payload = ['type' => 'catalog.version.updated', 'business_id' => 'biz-1', 'event_id' => 'evt-2'];

    $this->postJson('/api/webhooks/fresha', $payload, [
        'x-fresha-signature' => 'not-a-real-signature',
    ])->assertStatus(401);

    Bus::assertNotDispatched(SyncFreshaCatalogDeltaJob::class);
});

it('accepts a valid signature and dispatches sync job for catalog.version.updated', function () {
    $payload = ['type' => 'catalog.version.updated', 'business_id' => 'biz-42', 'event_id' => 'evt-happy-1'];
    $body = json_encode($payload);
    $sig = signFreshaBody('http://localhost/api/webhooks/fresha', $body, 'test-fresha-key');

    $this->postJson('/api/webhooks/fresha', $payload, [
        'x-fresha-signature' => $sig,
    ])
        ->assertOk()
        ->assertJson(['received' => true, 'queued' => true]);

    Bus::assertDispatched(
        SyncFreshaCatalogDeltaJob::class,
        fn (SyncFreshaCatalogDeltaJob $job) => true
    );
});

it('returns duplicate=true on second delivery of the same event_id', function () {
    $payload = ['type' => 'catalog.version.updated', 'business_id' => 'biz-7', 'event_id' => 'evt-dup-1'];
    $body = json_encode($payload);
    $sig = signFreshaBody('http://localhost/api/webhooks/fresha', $body, 'test-fresha-key');

    $this->postJson('/api/webhooks/fresha', $payload, ['x-fresha-signature' => $sig])
        ->assertOk()
        ->assertJson(['queued' => true]);

    $this->postJson('/api/webhooks/fresha', $payload, ['x-fresha-signature' => $sig])
        ->assertOk()
        ->assertJson(['received' => true, 'duplicate' => true]);

    // Only ONE dispatch — the second was deduped.
    Bus::assertDispatchedTimes(SyncFreshaCatalogDeltaJob::class, 1);
});

it('deletes integration on oauth.authorization.revoked', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_FRESHA,
        'external_account_id' => 'biz-revoke',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = ['type' => 'oauth.authorization.revoked', 'business_id' => 'biz-revoke', 'event_id' => 'evt-revoke-1'];
    $body = json_encode($payload);
    $sig = signFreshaBody('http://localhost/api/webhooks/fresha', $body, 'test-fresha-key');

    $this->postJson('/api/webhooks/fresha', $payload, ['x-fresha-signature' => $sig])
        ->assertOk()
        ->assertJson(['received' => true, 'revoked' => true]);

    expect(DB::table('core.professional_integrations')
        ->where('external_account_id', 'biz-revoke')
        ->count())->toBe(0);

    Bus::assertNotDispatched(SyncFreshaCatalogDeltaJob::class);
});

it('returns ignored=type for an unknown event type', function () {
    $payload = ['type' => 'employee.something.weird', 'business_id' => 'biz-99', 'event_id' => 'evt-unknown-1'];
    $body = json_encode($payload);
    $sig = signFreshaBody('http://localhost/api/webhooks/fresha', $body, 'test-fresha-key');

    $this->postJson('/api/webhooks/fresha', $payload, ['x-fresha-signature' => $sig])
        ->assertOk()
        ->assertJson(['received' => true, 'ignored' => 'employee.something.weird']);

    Bus::assertNotDispatched(SyncFreshaCatalogDeltaJob::class);
});
