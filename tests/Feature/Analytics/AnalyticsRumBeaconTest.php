<?php

use Illuminate\Support\Facades\Log;

// The RUM beacon's two senders.
//
// apps/pages ships TWO real-user-monitoring beacons from the same document: an
// inline script in [...path].astro that sends {handle, ttfb, dom, load, fcp,
// lkg}, and the analytics module (tracker.ts) that sends {ttfb, fcp, lcp}
// through beacon.ts — which attaches `subdomain`, never `handle`. rum() read
// `handle` only, so 100% of the module's beacons were dropped at the identity
// gate and answered 200, and LCP — the field metric the sitepage is actually
// judged on — has never been recorded once.
//
// The identity gate accepts either key (they carry the same value: the site's
// subdomain IS its handle), and an unidentified beacon now leaves a warning
// instead of vanishing. The fake-200 stays: a visitor's browser must never
// learn anything from an analytics response.

it('accepts the tracker module beacon, which identifies the site by subdomain', function () {
    Log::shouldReceive('info')
        ->once()
        ->with('rum', Mockery::on(function (array $context) {
            expect($context['handle'])->toBe(hash('sha256', 'module-beacon'));
            expect($context['ttfb_ms'])->toBe(120);
            expect($context['fcp_ms'])->toBe(340);

            return true;
        }));

    $this->postJson('/api/public/analytics/rum', [
        'subdomain' => 'module-beacon',
        'ttfb' => 120,
        'fcp' => 340,
    ])->assertStatus(200);
});

it('records lcp, the metric only the module beacon can measure', function () {
    Log::shouldReceive('info')
        ->once()
        ->with('rum', Mockery::on(function (array $context) {
            expect($context['lcp_ms'])->toBe(1820);

            return true;
        }));

    $this->postJson('/api/public/analytics/rum', [
        'subdomain' => 'lcp-beacon',
        'lcp' => 1820,
    ])->assertStatus(200);
});

it('still reads handle, the key the inline [...path].astro beacon sends', function () {
    Log::shouldReceive('info')
        ->once()
        ->with('rum', Mockery::on(function (array $context) {
            expect($context['handle'])->toBe(hash('sha256', 'inline-beacon'));
            expect($context['load_ms'])->toBe(900);
            expect($context['lkg'])->toBeTrue();

            return true;
        }));

    $this->postJson('/api/public/analytics/rum', [
        'handle' => 'inline-beacon',
        'load' => 900,
        'lkg' => true,
    ])->assertStatus(200);
});

it('warns rather than silently discarding a beacon with no site identity', function () {
    Log::shouldReceive('info')->never();
    Log::shouldReceive('warning')
        ->once()
        ->with('analytics.rum_unidentified', Mockery::type('array'));

    $this->postJson('/api/public/analytics/rum', ['ttfb' => 120])
        ->assertStatus(200);
});
