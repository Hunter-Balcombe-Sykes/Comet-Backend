<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\Customer;
use App\Models\Core\User\User;
use Tests\Authz\AuthzTestCase;
use Tests\Authz\Fixtures;

uses(AuthzTestCase::class);

it('seeds three distinct identities', function () {
    Fixtures::ensureSeeded();

    expect(Fixtures::identityA()->id)->not->toBe(Fixtures::identityB()->id);
    expect(Fixtures::unclaimed()->status->value ?? Fixtures::unclaimed()->status)->toBe('unclaimed');
    expect(Fixtures::unclaimed()->primary_email)->toBeNull();
});

it('gives identity B a row for each seeded model', function () {
    Fixtures::ensureSeeded();

    expect(Fixtures::idFor(Site::class))->not->toBeNull();
    expect(Fixtures::idFor(Customer::class))->not->toBeNull();
    expect(Fixtures::idFor(User::class))->toBe(Fixtures::identityB()->id);
});

it('is idempotent', function () {
    $first = Fixtures::identityB()->id;

    Fixtures::ensureSeeded();

    expect(Fixtures::identityB()->id)->toBe($first);
});

it('survives a rolled-back transaction', function () {
    // AuthzTestCase already opened a transaction for this test; the fixtures
    // were committed before it, so they are visible now and will still exist
    // after this test's rollback. If this fails, ensureSeeded is running INSIDE
    // the per-test transaction and every case after the first sees no fixtures.
    $id = Fixtures::identityB()->id;

    expect(User::query()->find($id))->not->toBeNull();
    expect(DB::connection('pgsql')->transactionLevel())->toBeGreaterThan(0);
});
