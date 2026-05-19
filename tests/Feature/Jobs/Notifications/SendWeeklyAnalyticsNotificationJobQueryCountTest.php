<?php

use App\Jobs\Notifications\SendWeeklyAnalyticsNotificationJob;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();
    setupCommerceOrdersTables();
});

it('issues at most one metrics query per chunk regardless of professional count', function () {
    // Pin "now" so window math (subDays(7)->startOfDay() etc.) is deterministic
    // — yesterday must fall inside the window the job builds.
    $pinned = \Carbon\Carbon::parse('2026-05-06 12:00:00');
    \Illuminate\Support\Carbon::setTestNow($pinned);
    $occurredAt = $pinned->copy()->subDays(2)->toDateTimeString();
    $now = $pinned->toDateTimeString();
    $brandId = (string) Str::uuid();

    // Seed 5 active affiliate professionals, each with one in-window order.
    $ids = [];
    foreach (range(1, 5) as $i) {
        $id = (string) Str::uuid();
        $ids[] = $id;

        DB::connection('pgsql')->table('core.professionals')->insert([
            'id' => $id,
            'handle' => "pro{$i}",
            'handle_lc' => "pro{$i}",
            'display_name' => "Pro {$i}",
            'primary_email' => "pro{$i}@example.test",
            'professional_type' => 'affiliate',
            'account_type' => 'partner',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::connection('pgsql')->table('commerce.orders')->insert([
            'shopify_order_id' => "order-{$i}",
            'shopify_shop_domain' => 'shop.example.test',
            'brand_professional_id' => $brandId,
            'affiliate_professional_id' => $id,
            'status' => 'approved',
            'gross_cents' => $i * 1000,
            'net_cents' => $i * 1000,
            'commission_cents' => $i * 1000,
            'currency_code' => 'AUD',
            'shopify_updated_at' => $occurredAt,
            'occurred_at' => $occurredAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->times(5);

    DB::enableQueryLog();

    (new SendWeeklyAnalyticsNotificationJob)->handle($publisher);

    // After Phase 4 the job reads from commerce.orders directly. The <=1
    // query/chunk contract is what we care about — preserves the original
    // performance guarantee even though the table changed.
    $metricsQueries = array_filter(
        DB::getQueryLog(),
        fn ($q) => str_contains($q['query'], 'commerce.orders')
            && str_contains($q['query'], 'group by')
    );

    expect(count($metricsQueries))->toBeLessThanOrEqual(1);

    \Illuminate\Support\Carbon::setTestNow();
});
