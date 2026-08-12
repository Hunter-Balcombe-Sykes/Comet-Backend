<?php

use App\Site\Pools\PoolRegistry;

// Slice 3a §3.2: the service kind gets a pool. Not in LATEST_TAG_POOLS —
// a "latest service" is meaningless.

it('owns the service kind and nothing else', function () {
    expect(PoolRegistry::POOLS['services'])->toBe(['service']);
    expect(PoolRegistry::isPool('services'))->toBeTrue();
});

it('is not a Latest-tag pool', function () {
    expect(PoolRegistry::LATEST_TAG_POOLS)->not->toContain('services');
});

it('carries the reconciled shape for priced, undated items', function () {
    // Reconciled with slice 5a 2026-08-12: same rule, same ordering, so slice 4
    // inherits one convention rather than two. Hand-ordering is expressed by
    // PINNING, never by a new order_by operator.
    expect(PoolRegistry::SECTION_SHAPE['services'])->toBe([
        'rule' => [['op' => 'kind_is']],
        'order_by' => 'recency',
    ]);
});

it('gives the pool a page and a label', function () {
    expect(PoolRegistry::PAGE_KEYS['services'])->toBe('services');
    expect(PoolRegistry::PAGE_LABELS['services'])->toBe('Services');
});
