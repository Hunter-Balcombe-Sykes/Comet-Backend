<?php

// #W1-LIFE-3 / #W2-LIFE-2 regression (the two are ONE finding — same file,
// same lines, same evidence). SourceReconciler::applyIntent() re-read
// site.platform_connections for the (user, surface, resource) triple and, on
// a miss, did a bare `new IntegrationConnection(...)->save()`. Two concurrent
// reconciles for the same triple both miss; the loser's INSERT is refused by
// idx_platform_connections_unique_active
// (supabase/migrations/20260727110005_connections_idx_unique_active.sql) and
// raised an unhandled UniqueConstraintViolationException — a 500 / failed job
// where the correct outcome is the idempotent "already handled".
//
// The fix is a SAVEPOINT with the typed catch OUTSIDE it, not a catch in
// place: applyIntent() runs inside reconcile()'s LIFE-16 transaction, and on
// Postgres a raised 23505 aborts the WHOLE transaction (25P02), so a
// catch-and-re-read where the violation was raised cannot work. That half —
// "the loser's 23505 does not poison the outer transaction" — is unprovable
// here, because SQLite does not abort a transaction on a failed statement; it
// lives in tests/Postgres/SourceReconcilerConnectionRacePgTest.php.
//
// What this file DOES prove, and what the SQLite stand-in genuinely supports:
// the stand-in carries a faithful copy of idx_platform_connections_unique_active
// (tests/Pest.php), so the loser really is refused by that index, and the
// resolution is the WINNER's id rather than an exception or a phantom uuid.
//
// The race is injected the way tests/Feature/Ingest/SourceProvisionerInsertRaceTest.php
// does it: a DB::listen hook reproduces the ORDERING (the winner's row exists
// by the time the caller's own INSERT runs), not real concurrency.

use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Routing\Iri;
use App\Routing\Placement;
use App\Routing\RoutingContext;
use App\Routing\SourceReconciler;
use App\Routing\Verdict;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
});

// UNIQUE GLOBAL PREFIX (`srcRace`). Pest loads every file in the lane into one
// process, so a helper named the same as one in a sibling file is a fatal
// redeclare on a serial run and an invisible cross-file dependency under
// --parallel. Nothing here is borrowed from another file for the same reason.

function srcRaceIri(string $identifier): Iri
{
    return new Iri(
        raw: "https://{$identifier}.bandcamp.com",
        canonical: "https://{$identifier}.bandcamp.com",
        scheme: 'https',
        host: "{$identifier}.bandcamp.com",
        registrableKey: 'bandcamp.com',
        subdomain: $identifier,
        path: '',
        query: [],
        port: null,
    );
}

// bandcamp.artist deliberately: routing_class 'content' (NOT exclusive-auto),
// so this file exercises applyIntent()'s unique-active race with no
// exclusive-slot lock in the picture — that is SourceReconcilerExclusiveSlotLockTest's job.
function srcRacePlacement(string $identifier): Placement
{
    return new Placement(
        verdict: Verdict::Place,
        surfaceKey: 'bandcamp.artist',
        identifier: $identifier,
        blockReason: null,
    );
}

/** @return array<string, mixed> */
function srcRaceWinnerRow(string $id, string $userId, string $identifier): array
{
    return [
        'id' => $id,
        'user_id' => $userId,
        'surface_key' => 'bandcamp.artist',
        'routing_class' => 'content',
        'resource_id' => $identifier,
        'payload' => json_encode(['url' => "https://{$identifier}.bandcamp.com", 'source' => 'winner']),
        'is_active' => 1,
        'last_refresh_status' => 'pending',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ];
}

// ── The refusal reason, pinned first ──────────────────────────────────────

it('refuses a duplicate (user, surface, resource) insert with a UNIQUE violation — the stand-in really carries idx_platform_connections_unique_active', function () {
    // Without this, every assertion in the race test below could be satisfied
    // by a stand-in that silently accepts the duplicate: the reconciler would
    // never reach its catch, and "resolved to the winner" would be proving
    // nothing. This is the named refusal reason the fix handles — the partial
    // unique index on (user_id, surface_key, resource_id) WHERE deleted_at IS
    // NULL, migration 20260727110005.
    $user = createTenant('srcrace-idx');
    $identifier = 'idxproof'.Str::lower(Str::random(6));

    DB::table('site.platform_connections')->insert(
        srcRaceWinnerRow((string) Str::uuid(), (string) $user->id, $identifier)
    );

    expect(fn () => DB::table('site.platform_connections')->insert(
        srcRaceWinnerRow((string) Str::uuid(), (string) $user->id, $identifier)
    ))->toThrow(UniqueConstraintViolationException::class);
});

// ── The race ──────────────────────────────────────────────────────────────

it('resolves a connection insert lost to a concurrent winner to the WINNER\'s id, not a 500 and not a phantom uuid', function () {
    Queue::fake();

    $user = createTenant('srcrace-winner');
    $identifier = 'raceartist'.Str::lower(Str::random(6));
    $winnerId = (string) Str::uuid();

    // The injection point has to sit strictly BETWEEN applyIntent()'s re-read
    // and its INSERT — inject any earlier and the re-read finds the winner and
    // returns through the existing `$connection !== null` arm, which is a
    // different (already-working) code path and would make this test vacuous.
    // The intent INSERT is the landmark: upsertIntent() runs immediately
    // before applyIntent(), so the first platform_connections SELECT carrying
    // this identifier AFTER a source_intents statement is the re-read.
    $seenIntent = false;
    $injected = false;
    DB::listen(function ($query) use (&$seenIntent, &$injected, $identifier, $winnerId, $user) {
        if ($injected) {
            return; // fires exactly once.
        }
        if (str_contains($query->sql, 'source_intents')) {
            $seenIntent = true;

            return;
        }
        if (! $seenIntent || ! str_contains($query->sql, 'platform_connections')) {
            return;
        }
        if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            return;
        }
        if (! in_array($identifier, $query->bindings, true)) {
            return;
        }

        $injected = true;

        // A concurrent reconcile that already committed its row for this exact
        // triple. Written with the query builder, not Eloquent: this stands in
        // for another process, and firing IntegrationConnectionObserver here
        // would be this process doing the winner's side effects.
        DB::table('site.platform_connections')->insert(
            srcRaceWinnerRow($winnerId, (string) $user->id, $identifier)
        );
    });

    $result = app(SourceReconciler::class)->reconcile(
        srcRacePlacement($identifier),
        RoutingContext::forUser($user, 'bio_harvest'),
        srcRaceIri($identifier),
    );

    expect($injected)->toBeTrue('the race was never injected — every assertion below is vacuous');

    // 1. The resolved id IS the winner's persisted row. `->not->toThrow()`
    //    would be vacuous here: a fix that swallowed the violation and
    //    returned its own unpersisted uuid would satisfy it and leave the
    //    intent pointing at a connection that does not exist.
    expect($result['connection_id'])->toBe($winnerId)
        ->and($result['verdict'])->toBe('place');

    // 2. Exactly one connection row survives — the loser inserted nothing.
    $rows = DB::table('site.platform_connections')->where('user_id', $user->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe($winnerId);

    // 3. The settled intent points at the SAME id, committed. The whole
    //    reconcile transaction survived the violation.
    $intent = DB::table('routing.source_intents')->where('user_id', $user->id)->first();
    expect($intent)->not->toBeNull()
        ->and($intent->state)->toBe('applied')
        ->and($intent->connection_id)->toBe($winnerId);

    // 4. The loser takes the same early return as the "row already exists"
    //    arm: the winner already owns the enrichment, so no second
    //    ConnectFetchJob is bought for a row this call did not create.
    Queue::assertNotPushed(ConnectFetchJob::class);
});

it('still creates and enriches the connection when there is no race — the positive control', function () {
    Queue::fake();

    $user = createTenant('srcrace-clean');
    $identifier = 'cleanartist'.Str::lower(Str::random(6));

    // Mandatory counterpart: a "fix" that always resolved to a re-read would
    // pass every assertion above while never creating a connection at all.
    $result = app(SourceReconciler::class)->reconcile(
        srcRacePlacement($identifier),
        RoutingContext::forUser($user, 'bio_harvest'),
        srcRaceIri($identifier),
    );

    expect($result['connection_id'])->not->toBeNull();

    $connection = IntegrationConnection::query()->where('user_id', $user->id)->firstOrFail();
    expect((string) $connection->id)->toBe($result['connection_id'])
        ->and($connection->resource_id)->toBe($identifier);

    Queue::assertPushed(ConnectFetchJob::class);
});
