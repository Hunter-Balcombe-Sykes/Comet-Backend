<?php

use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectDriver;
use App\Ingest\Runtime\Effects\BilledEffectDriverRegistry;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
use App\Ingest\Runtime\Effects\BilledEffectResult;

function fakeDriver(string $kind, string $name, string $marker): BilledEffectDriver
{
    return new class($kind, $name, $marker) implements BilledEffectDriver
    {
        public function __construct(
            private string $kind,
            private string $name,
            private string $marker,
        ) {}

        public function supports(string $kind, string $name): bool
        {
            return $kind === $this->kind && $name === $this->name;
        }

        public function run(BilledEffectContext $ctx): BilledEffectResult
        {
            return BilledEffectResult::answered(['marker' => $this->marker]);
        }
    };
}

it('dispatches on the (kind, name) pair, not on kind alone', function () {
    $registry = new BilledEffectDriverRegistry([
        fakeDriver('actor', 'instagram', 'ig'),
        fakeDriver('actor', 'menu', 'menu'),
        fakeDriver('api', 'places.details', 'places'),
    ]);

    $ctx = new BilledEffectContext('actor', 'menu', [], null, null, null);

    expect($registry->for('actor', 'menu')?->run($ctx)->data)->toBe(['marker' => 'menu'])
        ->and($registry->for('actor', 'instagram')?->run($ctx)->data)->toBe(['marker' => 'ig'])
        ->and($registry->for('api', 'places.details')?->run($ctx)->data)->toBe(['marker' => 'places']);
});

it('returns null for an unmatched pair so the caller can throw', function () {
    $registry = new BilledEffectDriverRegistry([fakeDriver('actor', 'instagram', 'ig')]);

    expect($registry->for('actor', 'menu'))->toBeNull()
        ->and($registry->for('ai', 'instagram'))->toBeNull()
        ->and($registry->for('actor', 'Instagram'))->toBeNull();
});

it('returns the first driver that claims a pair', function () {
    $registry = new BilledEffectDriverRegistry([
        fakeDriver('api', 'places.details', 'first'),
        fakeDriver('api', 'places.details', 'second'),
    ]);

    $ctx = new BilledEffectContext('api', 'places.details', [], null, null, null);

    expect($registry->for('api', 'places.details')?->run($ctx)->data)->toBe(['marker' => 'first']);
});

it('carries an outcome distinct from its data', function () {
    expect(BilledEffectResult::answered(null)->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and(BilledEffectResult::answered(null)->data)->toBeNull()
        ->and(BilledEffectResult::noAnswer('google timed out')->outcome)->toBe(BilledEffectOutcome::NoAnswer)
        ->and(BilledEffectResult::noAnswer('google timed out')->reason)->toBe('google timed out');
});
