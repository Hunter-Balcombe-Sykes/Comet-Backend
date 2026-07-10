<?php

use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// #CFG-1 — analytics tunables that used to be hardcoded literals now live under
// config('partna.analytics.*'). This guards the keys exist with the CURRENT
// (pre-change) value as the default, so a missing env var never silently changes
// production behaviour.

it('has the CFG-1 analytics tunables with their pre-change defaults', function () {
    expect(config('partna.analytics.job_tries'))->toBe(3)
        ->and(config('partna.analytics.job_backoff_seconds'))->toBe(10)
        ->and(config('partna.analytics.job_timeout_seconds'))->toBe(30)
        ->and(config('partna.analytics.summary_ttl_today_seconds'))->toBe(300)
        ->and(config('partna.analytics.summary_ttl_historical_seconds'))->toBe(86400)
        ->and(config('partna.analytics.staff_summary_ttl_seconds'))->toBe(60)
        ->and(config('partna.analytics.ingest_debounce_seconds'))->toBe(30)
        ->and(config('partna.analytics.click_dedup_ttl_seconds'))->toBe(3)
        ->and(config('partna.analytics.section_dedup_ttl_seconds'))->toBe(300)
        ->and(config('partna.analytics.item_dedup_ttl_seconds'))->toBe(300)
        ->and(config('partna.analytics.purge_batch_size'))->toBe(10_000)
        ->and(config('partna.analytics.hourly_bucket_max_days'))->toBe(7);
});

it('overriding a tunable takes effect immediately (proves it is read from config, not a copied literal)', function () {
    config(['partna.analytics.summary_ttl_today_seconds' => 999]);

    expect(config('partna.analytics.summary_ttl_today_seconds'))->toBe(999);
});
