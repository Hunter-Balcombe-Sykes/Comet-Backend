<?php

use App\Jobs\Stripe\SyncStripeAccountStatusJob;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/**
 * Seed a real core.professionals row and return its id — the job calls
 * Professional::find(), so a seeded row is required (no alias mocking).
 */
function seedStripeSyncProfessional(string $stripeConnectStatus): string
{
    setupProfessionalsTable();

    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => "pro-{$id}",
        'handle_lc' => "pro-{$id}",
        'display_name' => 'Stripe Sync Pro',
        'professional_type' => 'brand',
        'account_type' => 'brand',
        'status' => 'active',
        'stripe_connect_status' => $stripeConnectStatus,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

it('logs skipped_not_connected when the professional has no Stripe account', function () {
    $proId = seedStripeSyncProfessional('not_connected');

    $service = Mockery::mock(StripeConnectService::class);
    $service->shouldNotReceive('syncAccountStatus');

    Log::spy();

    (new SyncStripeAccountStatusJob($proId))->handle($service);

    Log::shouldHaveReceived('info')
        ->once()
        ->with('stripe.sync_account_status.skipped_not_connected', Mockery::on(
            fn ($ctx) => $ctx['professional_id'] === $proId
        ));
});

it('logs synced on the happy path', function () {
    $proId = seedStripeSyncProfessional('active');

    $service = Mockery::mock(StripeConnectService::class);
    $service->shouldReceive('syncAccountStatus')
        ->once()
        ->with(Mockery::on(fn ($p) => $p->id === $proId));

    Log::spy();

    (new SyncStripeAccountStatusJob($proId))->handle($service);

    Log::shouldHaveReceived('info')
        ->once()
        ->with('stripe.sync_account_status.synced', Mockery::on(
            fn ($ctx) => $ctx['professional_id'] === $proId
        ));
});

it('is placed on the stripe queue with uniqueness', function () {
    $job = new SyncStripeAccountStatusJob('pro-abc');

    expect($job->queue)->toBe('stripe')
        ->and($job->uniqueFor)->toBe(60)
        ->and($job->uniqueId())->toBe('pro-abc');
});
