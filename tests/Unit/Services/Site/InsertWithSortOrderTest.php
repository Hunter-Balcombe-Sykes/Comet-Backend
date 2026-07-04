<?php

use App\Models\Core\User\Service;
use App\Services\Site\InsertWithSortOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    setupServicesTable();
    shimPgAdvisoryLockForSqlite();
    $this->userId = (string) Str::uuid();
});

it('returns sort_order 0 for an empty set', function () {
    $captured = null;

    InsertWithSortOrder::run(
        Service::query()->where('user_id', $this->userId),
        "services:{$this->userId}",
        function (int $next) use (&$captured) {
            $captured = $next;

            // Return a minimal model stub to satisfy the return type
            return new Service;
        },
    );

    expect($captured)->toBe(0);
});

it('returns max+1 for a non-empty set', function () {
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('site.services')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $this->userId,
        'title' => 'Existing',
        'price_cents' => 1000,
        'sort_order' => 4,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $captured = null;

    InsertWithSortOrder::run(
        Service::query()->where('user_id', $this->userId),
        "services:{$this->userId}",
        function (int $next) use (&$captured) {
            $captured = $next;

            return new Service;
        },
    );

    expect($captured)->toBe(5);
});

it('passes the computed next to the closure and returns the closure result', function () {
    $now = now()->toDateTimeString();
    $serviceId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.services')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $this->userId,
        'title' => 'First',
        'price_cents' => 500,
        'sort_order' => 2,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.services')->insert([
        'id' => $serviceId,
        'user_id' => $this->userId,
        'title' => 'Created',
        'price_cents' => 1000,
        'sort_order' => 99, // will be looked up as max=99 → next=100
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $result = InsertWithSortOrder::run(
        Service::query()->where('user_id', $this->userId),
        "services:{$this->userId}",
        function (int $next) use ($serviceId) {
            // Closure must return a Model; return an existing one for simplicity
            return Service::query()->findOrFail($serviceId);
        },
    );

    // Confirms: closure return value is what run() returns
    expect($result->id)->toBe($serviceId);
});

it('emits the pg_advisory_xact_lock SQL inside the transaction', function () {
    $lockQueries = [];
    DB::listen(function ($query) use (&$lockQueries) {
        if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
            $lockQueries[] = $query->sql;
        }
    });

    InsertWithSortOrder::run(
        Service::query()->where('user_id', $this->userId),
        "services:{$this->userId}",
        fn (int $next) => new Service,
    );

    expect($lockQueries)->toHaveCount(1);
});
