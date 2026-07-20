<?php

use App\Mail\EarlyAccess\EarlyAccessInviteMail;
use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Services\EarlyAccess\EarlyAccessService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/**
 * Audit-fix LIFE-4 / LIFE-8 — EarlyAccessService::invite() no longer trusts a
 * pre-loaded, possibly-stale $signup instance for its signed-up guard, and
 * markSignedUp()'s swallowed-exception catch reports to Nightwatch.
 *
 * Follow-up fix (independent review): Mail::queue() was pulled OUT of the
 * DB::transaction() closure. This deploy runs QUEUE_CONNECTION=sync, so
 * Mail::queue() sends synchronously in-process — issuing it from inside the
 * transaction held a live Postgres FOR UPDATE lock for the duration of an
 * SMTP call. Same class of bug as TXN-102 (AccountDeletionService).
 *
 * lockForUpdate() is a no-op on the SQLite test connection, so nothing here
 * proves real cross-connection row locking. What IS proven:
 *   - invite() binds its guard to the DB's current row, not the caller's
 *     stale in-memory copy (this assertion fails against pre-fix code — see
 *     the first test below, hand-verified by reverting the fix).
 *   - invite()'s row read + status flip are atomic: a DB failure inside the
 *     transaction rolls the flip back (forced via a CHECK constraint, not
 *     via Mail — mail is no longer part of the transaction, so it can no
 *     longer prove atomicity).
 *   - invite() queues mail only AFTER the transaction commits, never while
 *     it's still open (proven via DB::transactionLevel() at call time).
 *   - invite() queues NO mail at all when the signed-up guard trips.
 *   - markSignedUp()'s catch block calls report() and still returns
 *     normally — no exception escapes the method.
 */
beforeEach(function () {
    setupEarlyAccessTable();
    DB::connection('pgsql')->statement('DELETE FROM core.early_access_signups');
});

function eaLifecycleWaitlistRow(string $email): EarlyAccessSignup
{
    return EarlyAccessSignup::query()->create([
        'email' => $email,
        'email_lc' => $email,
        'type' => 'partna',
        'status' => EarlyAccessSignup::STATUS_WAITLIST,
        'source' => 'marketing',
        'platforms' => [],
    ]);
}

it('LIFE-4: invite() binds its signed-up guard to the DB row, not a stale pre-loaded instance', function () {
    Mail::fake();

    $row = eaLifecycleWaitlistRow('stale-race@example.test');

    // A second request (or another process) flips the row to signed_up
    // behind $row's back. $row is never refreshed, so its in-memory status
    // still reads 'waitlist' — this is the exact staleness the race exploits.
    EarlyAccessSignup::query()->whereKey($row->id)->update([
        'status' => EarlyAccessSignup::STATUS_SIGNED_UP,
        'signed_up_at' => now(),
    ]);
    expect($row->status)->toBe(EarlyAccessSignup::STATUS_WAITLIST);

    $service = app(EarlyAccessService::class);
    $token = $service->invite($row);

    // Pre-fix, invite() trusts $row's stale status, mints a token, and
    // overwrites the DB's signed_up state back to 'invited'. This is the
    // assertion that fails against the old code.
    expect($token)->toBeNull();

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(EarlyAccessSignup::STATUS_SIGNED_UP)
        ->and($fresh->invite_token_hash)->toBeNull();

    Mail::assertNothingQueued();
});

it('LIFE-4: invite() wraps the status flip in a transaction — a DB failure inside it rolls back the status flip', function () {
    // Recreate the table with a CHECK constraint that blocks the exact
    // UPDATE invite() performs (status -> 'invited'). This forces a genuine
    // DB failure INSIDE the transaction without routing through Mail — mail
    // now runs strictly after the transaction commits, so throwing from
    // Mail::queue() can no longer be used to prove atomicity of the write.
    DB::connection('pgsql')->statement('DROP TABLE core.early_access_signups');
    DB::connection('pgsql')->statement("CREATE TABLE core.early_access_signups (
        id TEXT PRIMARY KEY,
        email TEXT NOT NULL,
        email_lc TEXT NOT NULL UNIQUE,
        type TEXT NOT NULL,
        workplace_or_industry TEXT NULL,
        platforms TEXT NOT NULL DEFAULT '[]',
        status TEXT NOT NULL DEFAULT 'waitlist',
        source TEXT NOT NULL DEFAULT 'marketing',
        invited_at TEXT NULL,
        invite_token_hash TEXT NULL,
        invite_meta TEXT NULL,
        invited_by TEXT NULL,
        signed_up_at TEXT NULL,
        consent_ip_hash TEXT NULL,
        consent_user_agent TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        CHECK (status != 'invited')
    )");

    // Mail must never be reached — the transaction throws before invite()
    // ever gets to the post-commit Mail::queue() call.
    Mail::shouldReceive('queue')->never();

    $row = eaLifecycleWaitlistRow('rollback@example.test');
    $service = app(EarlyAccessService::class);

    expect(fn () => $service->invite($row))->toThrow(QueryException::class);

    // The row must be exactly as it was before invite() ran — the failed
    // UPDATE rolled back with the rest of the transaction.
    $fresh = $row->fresh();
    expect($fresh->status)->toBe(EarlyAccessSignup::STATUS_WAITLIST)
        ->and($fresh->invite_token_hash)->toBeNull()
        ->and($fresh->invited_at)->toBeNull();
});

it('LIFE-4: invite() does not queue mail when the signed-up guard trips', function () {
    Mail::shouldReceive('queue')->never();

    $row = eaLifecycleWaitlistRow('already-signed-up@example.test');
    $row->forceFill(['status' => EarlyAccessSignup::STATUS_SIGNED_UP])->save();

    $service = app(EarlyAccessService::class);
    $token = $service->invite($row);

    expect($token)->toBeNull();
});

it('LIFE-4: queues the invite mail only after the DB transaction has committed', function () {
    $row = eaLifecycleWaitlistRow('post-commit@example.test');

    $transactionLevelWhenQueued = null;
    $queuedMailable = null;

    Mail::shouldReceive('queue')
        ->once()
        ->andReturnUsing(function (EarlyAccessInviteMail $mailable) use (&$transactionLevelWhenQueued, &$queuedMailable) {
            // Captured at the exact moment Mail::queue() runs. If the mail
            // send were still inside DB::transaction()'s closure (the bug
            // this fix removes), this would read 1, not 0.
            $transactionLevelWhenQueued = DB::connection('pgsql')->transactionLevel();
            $queuedMailable = $mailable;

            return null;
        });

    $service = app(EarlyAccessService::class);
    $token = $service->invite($row, 'staff-2');

    expect($token)->not->toBeNull()
        ->and($transactionLevelWhenQueued)->toBe(0)
        ->and($queuedMailable->recipientEmail)->toBe($row->email);

    // The row write must already be durable (committed) by the time mail is
    // queued — not merely "queued after transaction() returns" but committed.
    $fresh = $row->fresh();
    expect($fresh->status)->toBe(EarlyAccessSignup::STATUS_INVITED)
        ->and($fresh->invite_token_hash)->toBe(hash('sha256', $token));
});

it('LIFE-4: invite() still mints a token and queues mail for the ordinary, non-racing case', function () {
    Mail::fake();

    $row = eaLifecycleWaitlistRow('normal@example.test');
    $service = app(EarlyAccessService::class);

    $token = $service->invite($row, 'staff-1');

    expect($token)->not->toBeNull();

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(EarlyAccessSignup::STATUS_INVITED)
        ->and($fresh->invite_token_hash)->toBe(hash('sha256', $token))
        ->and($fresh->invited_by)->toBe('staff-1');

    Mail::assertQueued(EarlyAccessInviteMail::class);
});

it('LIFE-8: markSignedUp() reports the exception to Nightwatch and still returns normally', function () {
    Exceptions::fake();

    // Table intentionally dropped after beforeEach — a real (unmocked)
    // QueryException, mirroring the exact "SQLite mirror without the table"
    // case the existing catch-block comment already documents.
    DB::connection('pgsql')->statement('DROP TABLE core.early_access_signups');

    $service = app(EarlyAccessService::class);

    // Must not throw — bookkeeping failures are swallowed by design.
    $service->markSignedUp('nobody@example.test');

    Exceptions::assertReported(QueryException::class);
});
