<?php

use App\Jobs\Stripe\MonitorManualRefundQueueJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupCommissionPayoutsTable();
});

/**
 * Insert a commission_payouts row flagged for manual refund.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeManualRefundPayout(array $overrides = []): string
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('commerce.commission_payouts')->insert(array_merge([
        'id' => $id,
        'needs_manual_refund' => 1,
        'status' => 'pending',
        'gross_commission_cents' => 10000,
        'currency_code' => 'AUD',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return $id;
}

it('logs empty when no manual refund payouts exist', function () {
    Log::spy();

    (new MonitorManualRefundQueueJob)->handle();

    Log::shouldHaveReceived('info')
        ->once()
        ->with('payout.manual_refund_digest.empty');

    Log::shouldNotHaveReceived('warning');
});

it('logs a warning digest when open manual refund payouts exist', function () {
    makeManualRefundPayout();
    makeManualRefundPayout();

    Log::spy();

    (new MonitorManualRefundQueueJob)->handle();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($key, $context) => $key === 'payout.manual_refund_digest'
            && $context['count'] === 2
            && count($context['payouts']) === 2
        );
});

it('excludes cancelled and failed payouts from the digest', function () {
    makeManualRefundPayout(['status' => 'cancelled']);
    makeManualRefundPayout(['status' => 'failed']);
    makeManualRefundPayout(['status' => 'pending']);

    Log::spy();

    (new MonitorManualRefundQueueJob)->handle();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($key, $context) => $key === 'payout.manual_refund_digest'
            && $context['count'] === 1
        );
});

it('caps processed rows at 200 but reports the true total count', function () {
    // Insert 205 open manual-refund payouts.
    $now = now()->toDateTimeString();
    $rows = [];
    for ($i = 0; $i < 205; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid(),
            'needs_manual_refund' => 1,
            'status' => 'pending',
            'gross_commission_cents' => 5000,
            'currency_code' => 'AUD',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    DB::connection('pgsql')->table('commerce.commission_payouts')->insert($rows);

    Log::spy();

    (new MonitorManualRefundQueueJob)->handle();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function ($key, $context) {
            // True total must be 205 so ops sees the full backlog size.
            expect($key)->toBe('payout.manual_refund_digest');
            expect($context['count'])->toBe(205);
            // Only 200 rows are fetched and included in the payouts list.
            expect(count($context['payouts']))->toBe(200);

            return true;
        });
});
