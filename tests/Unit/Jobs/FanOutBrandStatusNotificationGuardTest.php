<?php

use App\Jobs\Notifications\FanOutBrandStatusNotificationJob;
use App\Jobs\Notifications\SendBrandStatusNotificationJob;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupProfessionalsTable();
    setupBrandLinkTables();
});

it('does not dispatch child jobs when the brand professional does not exist', function () {
    Bus::fake();

    $ghostBrandId = (string) Str::uuid();
    $affiliate = createAffiliateTenant('fan-out-ghost-aff');

    // Simulate a stale link row left behind after brand deletion.
    \Illuminate\Support\Facades\DB::connection('pgsql')->table('brand.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $ghostBrandId,
        'affiliate_professional_id' => $affiliate->id,
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $job = new FanOutBrandStatusNotificationJob($ghostBrandId, 'building');
    $job->handle();

    Bus::assertNothingBatched();
});

it('dispatches one child job per affiliate when the brand exists', function () {
    Bus::fake();

    $brand = createBrandTenant('fan-out-brand');
    $affiliateA = createAffiliateTenant('fan-out-aff-a');
    $affiliateB = createAffiliateTenant('fan-out-aff-b');

    foreach ([$affiliateA->id, $affiliateB->id] as $affiliateId) {
        \Illuminate\Support\Facades\DB::connection('pgsql')->table('brand.brand_partner_links')->insert([
            'id' => (string) Str::uuid(),
            'brand_professional_id' => $brand->id,
            'affiliate_professional_id' => $affiliateId,
            'status' => 'active',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    $job = new FanOutBrandStatusNotificationJob($brand->id, 'live');
    $job->handle();

    Bus::assertBatched(function (PendingBatch $batch) use ($affiliateA, $affiliateB) {
        if ($batch->queue() !== 'notifications') {
            return false;
        }

        if ($batch->jobs->count() !== 2) {
            return false;
        }

        $affiliateIds = $batch->jobs->pluck('affiliateProfessionalId')->all();

        return in_array($affiliateA->id, $affiliateIds, true)
            && in_array($affiliateB->id, $affiliateIds, true);
    });
});

it('passes correct status and brand id to each child job', function () {
    Bus::fake();

    $brand = createBrandTenant('fan-out-brand-status');
    $affiliate = createAffiliateTenant('fan-out-aff-status');

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('brand.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $brand->id,
        'affiliate_professional_id' => $affiliate->id,
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $job = new FanOutBrandStatusNotificationJob($brand->id, 'systems_down');
    $job->handle();

    Bus::assertBatched(function (PendingBatch $batch) use ($brand, $affiliate) {
        if ($batch->queue() !== 'notifications') {
            return false;
        }

        /** @var SendBrandStatusNotificationJob $childJob */
        $childJob = $batch->jobs->first();

        return $childJob->affiliateProfessionalId === $affiliate->id
            && $childJob->brandProfessionalId === $brand->id
            && $childJob->brandStatus === 'systems_down';
    });
});

it('splits affiliates into batches of at most 200', function () {
    Bus::fake();

    $brand = createBrandTenant('fan-out-brand-chunk');

    // Insert 350 affiliates — expect two batches: 200 + 150.
    // Each affiliate needs a real Professional row (with account_type='partner')
    // because the FanOut job's capability filter is now fail-closed on missing
    // Professional rows (PR #98). Without these the filter drops every job.
    $proRows = [];
    $linkRows = [];
    $now = now()->toDateTimeString();
    for ($i = 0; $i < 350; $i++) {
        $affiliateId = (string) Str::uuid();
        $proRows[] = [
            'id' => $affiliateId,
            'handle' => 'aff-'.substr($affiliateId, 0, 8),
            'handle_lc' => 'aff-'.substr($affiliateId, 0, 8),
            'professional_type' => 'affiliate',
            'account_type' => 'partner',
            'status' => 'active',
            'primary_email' => $affiliateId.'@example.test',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $linkRows[] = [
            'id' => (string) Str::uuid(),
            'brand_professional_id' => $brand->id,
            'affiliate_professional_id' => $affiliateId,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    \Illuminate\Support\Facades\DB::connection('pgsql')->table('core.professionals')->insert($proRows);
    \Illuminate\Support\Facades\DB::connection('pgsql')->table('brand.brand_partner_links')->insert($linkRows);

    $job = new FanOutBrandStatusNotificationJob($brand->id, 'live');
    $job->handle();

    Bus::assertBatchCount(2);

    // Verify the two batch sizes are 200 and 150 (order may vary).
    $sizes = collect(Bus::dispatchedBatches())
        ->map(fn (PendingBatch $b) => $b->jobs->count())
        ->sort()
        ->values()
        ->all();

    expect($sizes)->toBe([150, 200]);
});
