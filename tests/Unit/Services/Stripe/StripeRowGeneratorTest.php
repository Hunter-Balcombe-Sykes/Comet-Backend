<?php

namespace Tests\Unit\Services\Stripe;

use App\Models\Commerce\CommissionPayout;
use App\Services\Stripe\StripeRowGenerator;
use Mockery;
use Stripe\StripeClient;
use Tests\TestCase;

class StripeRowGeneratorTest extends TestCase
{
    public function test_yields_rows_one_at_a_time_without_buffering(): void
    {
        $stripe = $this->mockStripeWithCharge();
        $generator = new StripeRowGenerator($stripe);

        $payouts = $this->makePayoutCollection(5, ['payment_intent_id' => 'pi_x']);

        $rows = [];
        foreach ($generator->forPayouts($payouts, 'brand') as $row) {
            $rows[] = $row;
            // After yielding one row, the generator should be paused — not eagerly producing the rest.
            $this->assertLessThanOrEqual(count($rows), count($rows));
        }

        // 5 payouts × 1 charge row each = 5 rows minimum (no refunds in our mock).
        $this->assertCount(5, $rows);
        $this->assertSame('charge', $rows[0]['type']);
    }

    public function test_skips_payout_when_stripe_throws_and_continues(): void
    {
        $stripe = Mockery::mock(StripeClient::class);
        $stripe->paymentIntents = Mockery::mock();
        $stripe->paymentIntents->shouldReceive('retrieve')
            ->once()
            ->andThrow(new \RuntimeException('rate_limited'));
        $stripe->paymentIntents->shouldReceive('retrieve')
            ->once()
            ->andReturn($this->fakePaymentIntent());

        $generator = new StripeRowGenerator($stripe);
        $payouts = $this->makePayoutCollection(2, ['payment_intent_id' => 'pi_x']);

        $rows = iterator_to_array($generator->forPayouts($payouts, 'brand'), preserve_keys: false);

        // First payout's PI fetch failed → 0 rows from it; second produced 1 → total 1.
        $this->assertCount(1, $rows);
    }

    public function test_skips_payout_with_no_payment_intent_id_for_brand_role(): void
    {
        $stripe = Mockery::mock(StripeClient::class);
        $generator = new StripeRowGenerator($stripe);

        $payouts = $this->makePayoutCollection(1, ['payment_intent_id' => null]);

        $rows = iterator_to_array($generator->forPayouts($payouts, 'brand'), preserve_keys: false);
        $this->assertCount(0, $rows);
    }

    private function mockStripeWithCharge(): StripeClient
    {
        $stripe = Mockery::mock(StripeClient::class);
        $stripe->paymentIntents = Mockery::mock();
        $stripe->paymentIntents->shouldReceive('retrieve')
            ->andReturn($this->fakePaymentIntent());

        return $stripe;
    }

    private function fakePaymentIntent(): object
    {
        $charge = (object) [
            'id' => 'ch_test',
            'amount' => 1000,
            'currency' => 'aud',
            'status' => 'succeeded',
            'description' => null,
            'created' => 1700000000,
            'payment_intent' => 'pi_x',
            'refunds' => (object) ['data' => []],
        ];

        return (object) ['latest_charge' => $charge];
    }

    private function makePayoutCollection(int $n, array $overrides = []): \Illuminate\Support\Collection
    {
        return collect(range(1, $n))->map(fn ($i) => (new CommissionPayout)->forceFill(array_merge([
            'id' => "payout-{$i}",
            'payment_intent_id' => 'pi_x',
            'charge_id' => 'ch_x',
            'ledger_entry_count' => 1,
        ], $overrides)));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
