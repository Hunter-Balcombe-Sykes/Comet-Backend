<?php

use App\Jobs\Stripe\ReconcileStuckPayoutsJob;
use App\Models\Commerce\CommissionPayout;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;

// Tests for the STRP-D reconciliation job: payouts stuck in 'processing' beyond
// the BECS T+2 + 1 buffer window get their actual PI status pulled from Stripe
// and advanced via markPaymentIntentSucceeded / markPaymentIntentFailed.
//
// Combined with the STRP-C delete-on-failure pattern in the trait, this closes
// the recovery gap for webhooks that get permanently lost (Stripe's retry window
// exhausted after persistent handler failures).

beforeEach(function () {
    setupProfessionalsTable();
    setupCommerceOrdersTables();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        payment_intent_id TEXT,
        charge_id TEXT,
        status TEXT NOT NULL DEFAULT \'pending\',
        gross_commission_cents INTEGER NOT NULL DEFAULT 0,
        platform_fee_cents INTEGER NOT NULL DEFAULT 0,
        net_payout_cents INTEGER NOT NULL DEFAULT 0,
        currency_code TEXT NOT NULL DEFAULT \'AUD\',
        failure_reason TEXT,
        failure_code TEXT,
        ledger_entry_count INTEGER NOT NULL DEFAULT 0,
        eligible_after TEXT,
        processed_at TEXT,
        charge_cents INTEGER DEFAULT 0,
        retry_count INTEGER NOT NULL DEFAULT 0,
        needs_manual_refund INTEGER NOT NULL DEFAULT 0,
        transfer_completed_at TEXT,
        failure_category TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

function reconcileSeedPayout(array $overrides): CommissionPayout
{
    $id = $overrides['id'] ?? Str::uuid()->toString();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('commerce.commission_payouts')->insert(array_merge([
        'id' => $id,
        'brand_professional_id' => 'brand-1',
        'affiliate_professional_id' => 'aff-1',
        'status' => 'processing',
        'gross_commission_cents' => 10000,
        'platform_fee_cents' => 300,
        'net_payout_cents' => 9700,
        'currency_code' => 'AUD',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return CommissionPayout::find($id);
}

function reconcileJobWithStripeMock(StripeClient $stripeMock): ReconcileStuckPayoutsJob
{
    return new class($stripeMock) extends ReconcileStuckPayoutsJob
    {
        public function __construct(public StripeClient $injected)
        {
            parent::__construct();
        }

        protected function makeStripeClient(): StripeClient
        {
            return $this->injected;
        }
    };
}

it('advances a stuck succeeded payout via markPaymentIntentSucceeded', function () {
    $payout = reconcileSeedPayout([
        'id' => 'pay_stuck_succeeded',
        'payment_intent_id' => 'pi_stuck_ok',
        'status' => 'processing',
        'updated_at' => now()->subDays(4)->toDateTimeString(),
    ]);

    $pi = (object) ['id' => 'pi_stuck_ok', 'status' => 'succeeded', 'latest_charge' => 'ch_stuck_ok'];
    $paymentIntentsMock = Mockery::mock();
    $paymentIntentsMock->shouldReceive('retrieve')->once()->with('pi_stuck_ok')->andReturn($pi);

    $stripeMock = Mockery::mock(StripeClient::class)->makePartial();
    $stripeMock->paymentIntents = $paymentIntentsMock;

    $payoutService = Mockery::mock(CommissionPayoutService::class);
    $payoutService->shouldReceive('markPaymentIntentSucceeded')
        ->once()
        ->withArgs(fn ($p, $charge) => $p->id === 'pay_stuck_succeeded' && $charge === 'ch_stuck_ok');

    reconcileJobWithStripeMock($stripeMock)->handle($payoutService);
});

it('marks a stuck terminally-failed payout via markPaymentIntentFailed', function () {
    reconcileSeedPayout([
        'id' => 'pay_stuck_failed',
        'payment_intent_id' => 'pi_stuck_fail',
        'status' => 'processing',
        'updated_at' => now()->subDays(4)->toDateTimeString(),
    ]);

    $pi = (object) [
        'id' => 'pi_stuck_fail',
        'status' => 'requires_payment_method',
        'last_payment_error' => (object) ['code' => 'card_declined', 'message' => 'Card declined.'],
    ];
    $paymentIntentsMock = Mockery::mock();
    $paymentIntentsMock->shouldReceive('retrieve')->once()->andReturn($pi);

    $stripeMock = Mockery::mock(StripeClient::class)->makePartial();
    $stripeMock->paymentIntents = $paymentIntentsMock;

    $payoutService = Mockery::mock(CommissionPayoutService::class);
    $payoutService->shouldReceive('markPaymentIntentFailed')
        ->once()
        ->withArgs(fn ($p, $code) => $p->id === 'pay_stuck_failed' && $code === 'card_declined');

    reconcileJobWithStripeMock($stripeMock)->handle($payoutService);
});

it('skips payouts that are still processing at Stripe (BECS settlement in flight)', function () {
    reconcileSeedPayout([
        'id' => 'pay_still_processing',
        'payment_intent_id' => 'pi_still_processing',
        'status' => 'processing',
        'updated_at' => now()->subDays(4)->toDateTimeString(),
    ]);

    $pi = (object) ['id' => 'pi_still_processing', 'status' => 'processing'];
    $paymentIntentsMock = Mockery::mock();
    $paymentIntentsMock->shouldReceive('retrieve')->once()->andReturn($pi);

    $stripeMock = Mockery::mock(StripeClient::class)->makePartial();
    $stripeMock->paymentIntents = $paymentIntentsMock;

    $payoutService = Mockery::mock(CommissionPayoutService::class);
    $payoutService->shouldNotReceive('markPaymentIntentSucceeded');
    $payoutService->shouldNotReceive('markPaymentIntentFailed');

    reconcileJobWithStripeMock($stripeMock)->handle($payoutService);
});

it('does not pick up payouts younger than the 3-day threshold', function () {
    reconcileSeedPayout([
        'id' => 'pay_too_young',
        'payment_intent_id' => 'pi_too_young',
        'status' => 'processing',
        'updated_at' => now()->subDays(2)->toDateTimeString(),
    ]);

    $paymentIntentsMock = Mockery::mock();
    $paymentIntentsMock->shouldNotReceive('retrieve');

    $stripeMock = Mockery::mock(StripeClient::class)->makePartial();
    $stripeMock->paymentIntents = $paymentIntentsMock;

    $payoutService = Mockery::mock(CommissionPayoutService::class);

    reconcileJobWithStripeMock($stripeMock)->handle($payoutService);
});

it('continues processing other payouts when one retrieve fails', function () {
    reconcileSeedPayout([
        'id' => 'pay_retrieve_fails',
        'payment_intent_id' => 'pi_retrieve_fails',
        'status' => 'processing',
        'updated_at' => now()->subDays(5)->toDateTimeString(),
    ]);
    reconcileSeedPayout([
        'id' => 'pay_retrieve_ok',
        'payment_intent_id' => 'pi_retrieve_ok',
        'status' => 'processing',
        'updated_at' => now()->subDays(4)->toDateTimeString(),
    ]);

    $paymentIntentsMock = Mockery::mock();
    $paymentIntentsMock->shouldReceive('retrieve')
        ->with('pi_retrieve_fails')
        ->andThrow(new \RuntimeException('Network blip'));
    $paymentIntentsMock->shouldReceive('retrieve')
        ->with('pi_retrieve_ok')
        ->andReturn((object) ['id' => 'pi_retrieve_ok', 'status' => 'succeeded', 'latest_charge' => 'ch_ok']);

    $stripeMock = Mockery::mock(StripeClient::class)->makePartial();
    $stripeMock->paymentIntents = $paymentIntentsMock;

    $payoutService = Mockery::mock(CommissionPayoutService::class);
    $payoutService->shouldReceive('markPaymentIntentSucceeded')
        ->once()
        ->withArgs(fn ($p) => $p->id === 'pay_retrieve_ok');

    reconcileJobWithStripeMock($stripeMock)->handle($payoutService);
});

// §28.17 OBS-3 — the per-PaymentIntent retrieve catch must call report($e)
// so Nightwatch surfaces individual Stripe degradation incidents (not only
// whole-job retry exhaustion via failed()).
it('reports per-payout Stripe retrieve failures via report() (OBS-3)', function () {
    \Illuminate\Support\Facades\Exceptions::fake();

    reconcileSeedPayout([
        'id' => 'pay_retrieve_fails',
        'payment_intent_id' => 'pi_throws',
        'status' => 'processing',
        'updated_at' => now()->subDays(4)->toDateTimeString(),
    ]);

    $paymentIntentsMock = Mockery::mock();
    $paymentIntentsMock->shouldReceive('retrieve')
        ->once()
        ->with('pi_throws')
        ->andThrow(new \RuntimeException('Stripe API timeout'));

    $stripeMock = Mockery::mock(StripeClient::class)->makePartial();
    $stripeMock->paymentIntents = $paymentIntentsMock;

    // Sweep continues despite the per-payout throw — markPaymentIntentSucceeded
    // never fires for this PI but the job itself completes.
    $payoutService = Mockery::mock(CommissionPayoutService::class);
    $payoutService->shouldNotReceive('markPaymentIntentSucceeded');
    $payoutService->shouldNotReceive('markPaymentIntentFailed');

    reconcileJobWithStripeMock($stripeMock)->handle($payoutService);

    \Illuminate\Support\Facades\Exceptions::assertReported(\RuntimeException::class);
});
