<?php

use App\Jobs\Platforms\ConnectFetchJob;
use App\Jobs\Platforms\ScheduleConnectRetryJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\VimeoApi;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// T2 (2026-08-27 unclaimed-signup quality plan, issue 1): a system-initiated
// connect whose FIRST fetch fails must not lose the connection forever. The
// observed failure mode (three first-fetch losses across the 2026-08-27 test
// builds, while an identical fetch succeeded seconds later from the same
// environment) is intermittent vendor flakiness — the F26 delete is only
// correct when a human is watching the connect modal and can retry. System
// rows keep the failed row and get a spaced retry chain instead; the delete
// still happens once the chain is exhausted (F26's phantom-row concern), and
// interactive rows keep today's behaviour byte-identically.
//
// The retry is dispatched via ScheduleConnectRetryJob (non-unique) rather
// than a delayed self-dispatch: ConnectFetchJob is ShouldBeUnique and its
// lock is still held inside handle(), so a delayed self-dispatch would be
// silently dropped — and putting the attempt in uniqueId() would break the
// mutual exclusion ConnectFetchSystemInitiatedTest pins.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function cfsrUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

function cfsrPendingVimeoRow(User $user, array $extra = []): IntegrationConnection
{
    return IntegrationConnection::create(array_merge([
        'user_id' => $user->id,
        'platform' => 'vimeo',
        'resource_id' => 'acct-'.$user->handle,
        'payload' => ['apiPath' => 'someuser', 'url' => 'https://vimeo.com/someuser', 'link' => 'https://vimeo.com/someuser'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => null,
    ], $extra));
}

it('keeps a system-initiated row on a first-fetch vendor miss and schedules a spaced retry', function () {
    Queue::fake();
    $user = cfsrUser('cfsr1');
    $connection = cfsrPendingVimeoRow($user);

    $this->mock(VimeoApi::class, fn ($m) => $m->shouldReceive('fetchVideos')->once()->andReturn([]));

    $job = new ConnectFetchJob($connection->id, 'vimeo', systemInitiated: true);
    app()->call([$job, 'handle']);

    // The row SURVIVES — not soft-deleted. (fresh() bypasses global scopes,
    // so it returns even a trashed row — trashed() is the honest probe.)
    $fresh = $connection->fresh();
    expect($fresh->trashed())->toBeFalse();
    expect($fresh->last_refresh_status)->toBe('unavailable');
    expect($fresh->consecutive_failures)->toBe(1);

    // A retry is scheduled at the first tier's delay.
    Queue::assertPushed(ScheduleConnectRetryJob::class, function (ScheduleConnectRetryJob $retry) use ($connection) {
        return $retry->connectionId === $connection->id
            && $retry->platform === 'vimeo'
            && (int) $retry->delay === ConnectFetchJob::SYSTEM_RETRY_DELAYS[0];
    });
});

it('still deletes an interactive row on a first-fetch vendor miss (F26 unchanged)', function () {
    Queue::fake();
    $user = cfsrUser('cfsr2');
    $connection = cfsrPendingVimeoRow($user);

    $this->mock(VimeoApi::class, fn ($m) => $m->shouldReceive('fetchVideos')->once()->andReturn([]));

    $job = new ConnectFetchJob($connection->id, 'vimeo'); // interactive default
    app()->call([$job, 'handle']);

    expect($connection->fresh()->trashed())->toBeTrue(); // F26 soft delete
    Queue::assertNotPushed(ScheduleConnectRetryJob::class);
});

it('escalates the delay by the failure count and deletes once the chain is exhausted', function () {
    Queue::fake();
    $user = cfsrUser('cfsr3');

    // One failure short of the cap: this failure schedules the LAST tier.
    $connection = cfsrPendingVimeoRow($user, [
        'consecutive_failures' => count(ConnectFetchJob::SYSTEM_RETRY_DELAYS) - 1,
    ]);
    $this->mock(VimeoApi::class, fn ($m) => $m->shouldReceive('fetchVideos')->once()->andReturn([]));
    $job = new ConnectFetchJob($connection->id, 'vimeo', systemInitiated: true);
    app()->call([$job, 'handle']);

    $lastTier = ConnectFetchJob::SYSTEM_RETRY_DELAYS[count(ConnectFetchJob::SYSTEM_RETRY_DELAYS) - 1];
    expect($connection->fresh()->trashed())->toBeFalse();
    Queue::assertPushed(ScheduleConnectRetryJob::class, fn ($r) => (int) $r->delay === $lastTier);

    // At the cap: no further retry — the F26 delete finally applies.
    $exhausted = cfsrPendingVimeoRow(cfsrUser('cfsr3b'), [
        'consecutive_failures' => count(ConnectFetchJob::SYSTEM_RETRY_DELAYS),
    ]);
    $this->mock(VimeoApi::class, fn ($m) => $m->shouldReceive('fetchVideos')->once()->andReturn([]));
    $job2 = new ConnectFetchJob($exhausted->id, 'vimeo', systemInitiated: true);
    app()->call([$job2, 'handle']);

    expect($exhausted->fresh()->trashed())->toBeTrue();
    Queue::assertPushed(ScheduleConnectRetryJob::class, 1); // only the pre-cap one above
});

it('does not retry a non-retriable shape error even when system-initiated', function () {
    Queue::fake();
    $user = cfsrUser('cfsr4');
    // Empty payload → FetchShapeException → terminal 'error' (canary path).
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-cfsr4',
        'payload' => [],
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => null,
    ]);

    $job = new ConnectFetchJob($connection->id, 'bandcamp', systemInitiated: true);
    app()->call([$job, 'handle']);

    // 'error' is not vendor flakiness — retrying cannot fix a missing identity
    // key, so F26 applies as before.
    expect($connection->fresh()->trashed())->toBeTrue();
    Queue::assertNotPushed(ScheduleConnectRetryJob::class);
});

it('a re-fetch failure on a row that HAS fetched ok before keeps the row and does not schedule the system chain', function () {
    Queue::fake();
    $user = cfsrUser('cfsr5');
    // last_refreshed_at set — this is a healthy row whose re-fetch failed; the
    // scheduled-refresh lane owns its recovery, not the connect retry chain.
    $connection = cfsrPendingVimeoRow($user, ['last_refreshed_at' => now()]);

    $this->mock(VimeoApi::class, fn ($m) => $m->shouldReceive('fetchVideos')->once()->andReturn([]));
    $job = new ConnectFetchJob($connection->id, 'vimeo', systemInitiated: true);
    app()->call([$job, 'handle']);

    expect($connection->fresh()->trashed())->toBeFalse();
    Queue::assertNotPushed(ScheduleConnectRetryJob::class);
});

it('ScheduleConnectRetryJob re-dispatches ConnectFetchJob as system-initiated', function () {
    Queue::fake();
    $user = cfsrUser('cfsr6');
    $connection = cfsrPendingVimeoRow($user, ['last_refresh_status' => 'unavailable', 'consecutive_failures' => 1]);

    $retry = new ScheduleConnectRetryJob($connection->id, 'vimeo');
    app()->call([$retry, 'handle']);

    Queue::assertPushed(ConnectFetchJob::class, function (ConnectFetchJob $job) use ($connection) {
        return $job->connectionId === $connection->id
            && $job->platform === 'vimeo'
            && $job->systemInitiated === true;
    });
});

it('ScheduleConnectRetryJob is a silent no-op when the row is gone or already ok', function () {
    Queue::fake();
    $user = cfsrUser('cfsr7');

    // Row soft-deleted (user disconnected / build pruned while the retry waited).
    $gone = cfsrPendingVimeoRow($user);
    $gone->delete();
    app()->call([new ScheduleConnectRetryJob($gone->id, 'vimeo'), 'handle']);

    // Row already recovered (an interactive retry or scheduled refresh beat us).
    $ok = cfsrPendingVimeoRow(cfsrUser('cfsr7b'), ['last_refresh_status' => 'ok', 'last_refreshed_at' => now()]);
    app()->call([new ScheduleConnectRetryJob($ok->id, 'vimeo'), 'handle']);

    Queue::assertNotPushed(ConnectFetchJob::class);
});
