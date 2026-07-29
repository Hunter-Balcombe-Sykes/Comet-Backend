<?php

// LIFE-16 §3 constraint 1 sentinel (plan 2026-07-28, Unit C): SourceReconciler
// ::reconcile() now wraps the intent write + applyIntent()'s connection
// create + the settle-UPDATE in one DB::transaction (see SourceReconciler.php).
// That is only safe because every side effect IntegrationConnection::save()
// can trigger — the Cloudflare purge, the site->touch() cascade, the
// ingest.sources sync whose catch(QueryException) would otherwise poison a
// Postgres transaction (SQLSTATE 25P02) — is deferred past commit by
// `IntegrationConnectionObserver::$afterCommit = true`
// (app/Observers/Core/IntegrationConnectionObserver.php:35+). If that flag
// were ever removed or weakened, those side effects would run INSIDE the
// reconciler's transaction: real I/O (queue dispatch) where this repo's
// transaction gold standard forbids it, and — the part a naive test would
// miss — they would fire even when the transaction goes on to roll back.
//
// Driver-independent: the deferral mechanism lives in Laravel's transaction
// manager / event dispatcher, not in any Postgres-specific behaviour, so this
// belongs in the fast SQLite lane. Contrast
// tests/Postgres/SourceReconcilerAtomicityTest.php, which proves the
// Postgres-only half of LIFE-16 (a real mid-transaction failure leaves zero
// rows, not a dangling `applied` intent).

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Routing\LinkRoutingService;
use App\Routing\RoutingContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();

    // #TEST-guard (plan §4d): the loser path of LIFE-15's advanceLiveIntent()
    // depends entirely on idx_source_intents_live existing in the SQLite
    // mirror. setupRoutingTables() (tests/Pest.php) creates it inside a
    // try/catch that silently swallows failure — assert it actually landed,
    // or this file (and the SQLite half of every other routing test) would
    // silently stop covering the loser path without ever going red.
    $indexes = collect(DB::connection('pgsql')->select("PRAGMA index_list('source_intents')"))->pluck('name');
    expect($indexes)->toContain('idx_source_intents_live');
});

it('does not dispatch the Cloudflare purge while the enclosing transaction is still open', function () {
    Queue::fake();
    $pro = createTenant('deferred-rollback');

    // Wrap a real Verdict::Place reconcile in an OUTER transaction the test
    // itself rolls back, by throwing after the route() call succeeds.
    $forced = new RuntimeException('test-forced-rollback');
    try {
        DB::transaction(function () use ($pro, $forced) {
            $result = app(LinkRoutingService::class)->route(
                'https://www.instagram.com/deferred_rollback_user',
                RoutingContext::forUser($pro, 'paste'),
            );
            expect($result['verdict'])->toBe('place');

            throw $forced;
        });
    } catch (RuntimeException $e) {
        expect($e)->toBe($forced);
    }

    // The whole write rolled back...
    expect(DB::table('routing.source_intents')->count())->toBe(0);

    // ...and — the actual sentinel — the purge job never fired at all while
    // it was still inside the (now rolled-back) transaction. If
    // IntegrationConnectionObserver ever loses $afterCommit = true, the job
    // is queued synchronously during IntegrationConnection::save(), i.e.
    // BEFORE the throw above is even reached, and this assertion goes red.
    Queue::assertNothingPushed();
});

it('dispatches the Cloudflare purge once reconcile() returns, with no outer transaction', function () {
    Queue::fake();
    $pro = createTenant('deferred-commit');

    $result = app(LinkRoutingService::class)->route(
        'https://www.instagram.com/deferred_commit_user',
        RoutingContext::forUser($pro, 'paste'),
    );

    expect($result['verdict'])->toBe('place')
        ->and(DB::table('routing.source_intents')->count())->toBe(1);

    // Pins the other half of the sentinel: deferral did not silently become
    // "never runs" — DB::transaction() runs its afterCommit callbacks
    // synchronously at commit, so by the time route() returns the job is
    // already queued.
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});
