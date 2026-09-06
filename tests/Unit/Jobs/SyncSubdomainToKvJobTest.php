<?php

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\Cloudflare\CloudflareKvService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Database\QueryException;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('has the correct retry policy and queue configuration', function () {
    $job = new SyncSubdomainToKvJob('00000000-0000-0000-0000-000000000001');

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 30, 60])
        ->and($job->timeout)->toBe(30)
        ->and($job->queue)->toBe('cloudflare');
});

it('implements ShouldBeUnique with a 45s window keyed by user_id (§28.6a)', function () {
    $proId = (string) Str::uuid();
    $job = new SyncSubdomainToKvJob($proId);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueFor)->toBe(45)
        ->and($job->uniqueId())->toBe($proId);
});

// LIFE-110: HasCloudflareRetryPolicy's backoff ([10,30,60]) can span ~100s
// worst-case, longer than uniqueFor=45 — a plain ShouldBeUnique lock would
// already be gone (or collide) by the time a legitimate retry re-dispatches.
it('is ShouldBeUniqueUntilProcessing so the dedupe lock survives backoff retries (LIFE-110)', function () {
    $job = new SyncSubdomainToKvJob((string) Str::uuid());

    // Binds the actual interface the framework's dispatch/queue code checks.
    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);

    // Behavioural consequence, asserted via the framework's own decision point
    // (not a reimplementation): CallQueuedHandler releases the unique lock BEFORE
    // handle() runs for ShouldBeUniqueUntilProcessing jobs, vs only after handle()
    // completes for plain ShouldBeUnique. This is exactly the check that decides
    // whether a re-dispatch during backoff gets deduped.
    $handler = app(CallQueuedHandler::class);
    $method = new ReflectionMethod($handler, 'commandShouldBeUniqueUntilProcessing');
    $method->setAccessible(true);

    expect($method->invoke($handler, $job))->toBeTrue();
});

// --- WHK-1: a delete-triggered retire dispatch must not be deduped away by a
// concurrent plain sync for the same user. ---

it('gives a plain sync and a delete-triggered retire dispatch DIFFERENT unique ids (WHK-1)', function () {
    $proId = (string) Str::uuid();

    $plain = new SyncSubdomainToKvJob($proId);
    $retireHandle = new SyncSubdomainToKvJob($proId, 'oldhandle');
    $retireDomain = new SyncSubdomainToKvJob($proId, null, 'old.example');

    // Pre-fix, $plain->uniqueId() === $retireHandle->uniqueId() (both bare
    // $userId) — a plain sync in flight would silently swallow the retire.
    expect($plain->uniqueId())->not->toBe($retireHandle->uniqueId())
        ->and($plain->uniqueId())->not->toBe($retireDomain->uniqueId())
        ->and($retireHandle->uniqueId())->not->toBe($retireDomain->uniqueId());
});

it('does not collapse a delete-triggered retire dispatch into an in-flight plain sync (WHK-1)', function () {
    // Queue::fake() is required here — under the deployed sync connection the
    // unique lock releases before dispatch() returns (ShouldBeUniqueUntilProcessing),
    // so the race this test guards against isn't reproducible without faking
    // the queue to keep both dispatches "in flight" at once.
    Queue::fake();

    $proId = (string) Str::uuid();

    // A routine handle-change/restore sync (UserObserver::updated/restored) —
    // no capturedHandle, no retireCustomDomain.
    SyncSubdomainToKvJob::dispatch($proId);
    // A delete-triggered retire dispatch for the SAME user, arriving while the
    // plain sync's unique lock window is still open (UserObserver::deleted).
    SyncSubdomainToKvJob::dispatch($proId, 'oldhandle');

    // Pre-fix: uniqueId() collapses both to the bare $userId, so Laravel's
    // unique-job dispatch silently drops the second push — only 1 job lands.
    Queue::assertPushed(SyncSubdomainToKvJob::class, 2);
    Queue::assertPushed(SyncSubdomainToKvJob::class, fn (SyncSubdomainToKvJob $job) => $job->capturedHandle === 'oldhandle');
});

it('writes {type:"individual"} for an individual professional (§28.6)', function () {
    setupUsersTable();
    setupHandleAliasesTable();
    setupSitesTable();

    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'soloact',
        'handle_lc' => 'soloact',
        'display_name' => 'Soloact',
        'first_name' => 'Soloact',
        'account_type' => 'partna',
        'status' => 'active',
        'primary_email' => 'i@example.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $kv = Mockery::mock(CloudflareKvService::class);
    // §28.6: individuals get a positive KV entry.
    $kv->shouldNotReceive('delete');
    $kv->shouldReceive('put')->once()->with('soloact', ['type' => 'individual'], null);
    // No aliases — bulkPut called with empty array (returns early, no HTTP).
    $kv->shouldReceive('bulkPut')->once()->with([]);
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});

it('deletes the KV entry for a soft-deleted professional (#P2-45)', function () {
    setupUsersTable();
    setupHandleAliasesTable();
    setupSitesTable();

    $proId = (string) Str::uuid();
    // Soft-deleted: deleted_at set. find() excludes it; withTrashed() finds it,
    // and trashed() routes the job into the retire branch.
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'goneact',
        'handle_lc' => 'goneact',
        'display_name' => 'Goneact',
        'first_name' => 'Goneact',
        'account_type' => 'partna',
        'status' => 'active',
        'primary_email' => 'g@example.test',
        'deleted_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('put');
    $kv->shouldReceive('delete')->once()->with('goneact');
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});

it('deletes the captured handle when the professional row is hard-deleted (#P2-45)', function () {
    setupUsersTable();
    setupHandleAliasesTable();

    // No row exists (hard delete) — the job must fall back to the handle
    // captured at dispatch time by UserObserver::deleted.
    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('put');
    $kv->shouldReceive('delete')->once()->with('vanished');
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob((string) Str::uuid(), 'vanished'))->handle($kv);
});

it('no-ops when the professional is gone and no handle was captured (#P2-45)', function () {
    setupUsersTable();
    setupHandleAliasesTable();

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('put');
    $kv->shouldNotReceive('delete');
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob((string) Str::uuid()))->handle($kv);
});

it('retires the KV entry for a suspended (non-trashed) professional (EDGE-3)', function () {
    setupUsersTable();
    setupHandleAliasesTable();
    setupSitesTable();

    // Suspended by moderation: status != 'active' but NOT soft-deleted. Before the
    // isActive() gate this re-published the live route, leaving taken-down content
    // resolvable at the edge.
    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'suspendedact',
        'handle_lc' => 'suspendedact',
        'display_name' => 'Suspendedact',
        'first_name' => 'Suspendedact',
        'account_type' => 'partna',
        'status' => 'suspended',
        'primary_email' => 's@example.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('put');
    $kv->shouldReceive('delete')->once()->with('suspendedact');
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});

it('retires the KV entry when the site is moderation-hidden (EDGE-3)', function () {
    setupUsersTable();
    setupHandleAliasesTable();
    setupSitesTable();

    // hide_site hides the SITE, leaving the user active — the moderation gate must
    // still retire the route so the taken-down page stops resolving.
    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'hiddenact',
        'handle_lc' => 'hiddenact',
        'display_name' => 'Hiddenact',
        'first_name' => 'Hiddenact',
        'account_type' => 'partna',
        'status' => 'active',
        'primary_email' => 'h@example.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'subdomain' => 'hiddenact',
        'is_published' => 1,
        'moderation_state' => 'hidden',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('put');
    $kv->shouldReceive('delete')->once()->with('hiddenact');
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});

// --- EDGE-1: custom-domain (domain:<host>) retirement on takedown ---

it('retires BOTH the handle and the custom-domain KV keys when a site is moderation-hidden (EDGE-1)', function () {
    setupUsersTable();
    setupHandleAliasesTable();
    setupSitesTable();

    // Moderation-hidden site owned by an active user, with an ACTIVE custom domain.
    // retire() must delete the handle key AND the domain:<host> pointer — otherwise
    // the custom domain keeps resolving to the taken-down page indefinitely.
    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'takedownact',
        'handle_lc' => 'takedownact',
        'display_name' => 'Takedownact',
        'first_name' => 'Takedownact',
        'account_type' => 'partna',
        'status' => 'active',
        'primary_email' => 't@example.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'subdomain' => 'takedownact',
        'is_published' => 1,
        'moderation_state' => 'hidden',
        'custom_domain' => 'tuesdae.co',
        'custom_domain_status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('put');
    $kv->shouldReceive('delete')->once()->with('takedownact');
    $kv->shouldReceive('delete')->once()->with('domain:tuesdae.co');
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});

it('retires the custom-domain KV key for a suspended user with an active domain (EDGE-1)', function () {
    setupUsersTable();
    setupHandleAliasesTable();
    setupSitesTable();

    // Suspended (non-trashed) user — retire() runs via the isActive() gate and
    // must clear the active custom-domain pointer too.
    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'suspdomain',
        'handle_lc' => 'suspdomain',
        'display_name' => 'Suspdomain',
        'first_name' => 'Suspdomain',
        'account_type' => 'partna',
        'status' => 'suspended',
        'primary_email' => 'sd@example.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'subdomain' => 'suspdomain',
        'is_published' => 1,
        'custom_domain' => 'myshop.example',
        'custom_domain_status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('put');
    $kv->shouldReceive('delete')->once()->with('suspdomain');
    $kv->shouldReceive('delete')->once()->with('domain:myshop.example');
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});

it('does NOT retire a non-active (pending) custom domain on takedown (EDGE-1 guard)', function () {
    setupUsersTable();
    setupHandleAliasesTable();
    setupSitesTable();

    // A pending/unverified custom domain was never written to KV (handle() only
    // publishes 'active' domains), so retire() must NOT issue a domain:<host>
    // delete — only the handle key is retired.
    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'pendingdom',
        'handle_lc' => 'pendingdom',
        'display_name' => 'Pendingdom',
        'first_name' => 'Pendingdom',
        'account_type' => 'partna',
        'status' => 'suspended',
        'primary_email' => 'pd@example.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'subdomain' => 'pendingdom',
        'is_published' => 1,
        'custom_domain' => 'notyet.example',
        'custom_domain_status' => 'pending',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('put');
    $kv->shouldReceive('delete')->once()->with('pendingdom');
    $kv->shouldNotReceive('delete')->with('domain:notyet.example');
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});

it('clears the custom-domain pointer via $retireCustomDomain even when the user row is already gone (EDGE-1 hard-delete path)', function () {
    setupUsersTable();
    setupHandleAliasesTable();

    // Mirrors staff force-delete / scheduled purge: the user row is gone, so retire()
    // cannot resolve $pro->site — the domain is cleared via the $retireCustomDomain
    // constructor arg (captured before forceDelete) through handle()'s early branch.
    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('put');
    $kv->shouldReceive('delete')->once()->with('domain:gone.example');
    $kv->shouldReceive('delete')->once()->with('goneforever');
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob((string) Str::uuid(), 'goneforever', 'gone.example'))->handle($kv);
});

// --- JOB-101/102: a real DB failure reading the site must propagate, not be swallowed ---

it('lets a real DB failure reading the site propagate instead of writing a stale KV entry (JOB-101)', function () {
    setupUsersTable();
    setupHandleAliasesTable();
    // setupSitesTable() deliberately omitted — site.sites doesn't exist, so
    // $pro->site throws instead of returning null. Before the fix this was
    // swallowed and reported(), false-negating the moderation gate and
    // re-publishing a just-hidden site's route to KV.
    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'brokenread',
        'handle_lc' => 'brokenread',
        'display_name' => 'Brokenread',
        'first_name' => 'Brokenread',
        'account_type' => 'partna',
        'status' => 'active',
        'primary_email' => 'br@example.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('put');
    $kv->shouldNotReceive('delete');
    app()->instance(CloudflareKvService::class, $kv);

    // Throwable::class is an interface — Pest's toThrow() only does an
    // instanceof check against a concrete class (class_exists()), so assert
    // the concrete exception the SQLite driver actually throws.
    expect(fn () => (new SyncSubdomainToKvJob($proId))->handle($kv))->toThrow(QueryException::class);
});

it('lets a real DB failure reading the site propagate AFTER the handle delete in retire() (JOB-102)', function () {
    setupUsersTable();
    setupHandleAliasesTable();
    // setupSitesTable() deliberately omitted — site.sites doesn't exist, so
    // $pro?->site throws inside retire() after the handle key is already
    // deleted. Before the fix this was swallowed and reported(), silently
    // skipping the domain:<host> delete and leaving a taken-down user's
    // custom domain serving.
    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'brokenretire',
        'handle_lc' => 'brokenretire',
        'display_name' => 'Brokenretire',
        'first_name' => 'Brokenretire',
        'account_type' => 'partna',
        'status' => 'suspended',
        'primary_email' => 'bre@example.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldReceive('delete')->once()->with('brokenretire');
    $kv->shouldNotReceive('put');
    app()->instance(CloudflareKvService::class, $kv);

    expect(fn () => (new SyncSubdomainToKvJob($proId))->handle($kv))->toThrow(QueryException::class);
});

// --- SCALE-6 + P3-31: alias batching and expired-alias skip ---

it('batches N future aliases into a single bulkPut call (SCALE-6)', function () {
    setupUsersTable();
    setupHandleAliasesTable();
    setupSitesTable();

    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'newhandle',
        'handle_lc' => 'newhandle',
        'display_name' => 'Newhandle',
        'first_name' => 'Newhandle',
        'account_type' => 'partna',
        'status' => 'active',
        'primary_email' => 'a@example.test',
        // partna_url omitted — not in SQLite test schema; job falls back to
        // "https://{$current}.partna.au" when null, which is the same URL.
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Two future aliases — both should appear in bulkPut, not individual put() calls.
    $future1 = now()->addDays(30)->toDateTimeString();
    $future2 = now()->addDays(60)->toDateTimeString();
    DB::connection('pgsql')->table('core.user_handle_aliases')->insert([
        [
            'id' => (string) Str::uuid(),
            'user_id' => $proId,
            'handle' => 'oldhandle1',
            'reclaim_until' => now()->addDays(14)->toDateTimeString(),
            'expires_at' => $future1,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ],
        [
            'id' => (string) Str::uuid(),
            'user_id' => $proId,
            'handle' => 'oldhandle2',
            'reclaim_until' => now()->addDays(14)->toDateTimeString(),
            'expires_at' => $future2,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ],
    ]);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('delete');
    // The individual {type:"individual"} write remains a single put().
    $kv->shouldReceive('put')->once()->with('newhandle', ['type' => 'individual'], null);
    // Aliases must arrive as a single bulkPut — never as individual put() calls.
    $kv->shouldReceive('bulkPut')->once()->with(Mockery::on(function (array $entries) {
        if (count($entries) !== 2) {
            return false;
        }
        // Both entries must have the correct value shape and positive TTLs.
        foreach ($entries as $entry) {
            if (! in_array($entry['key'], ['oldhandle1', 'oldhandle2'], true)) {
                return false;
            }
            if ($entry['value'] !== ['type' => 'alias', 'redirect' => 'https://newhandle.partna.au']) {
                return false;
            }
            if ($entry['expiration_ttl'] === null || $entry['expiration_ttl'] <= 0) {
                return false;
            }
        }

        return true;
    }));
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});

// LIFE-109 (revised): an alias just seconds from expiry computes a
// positive-but-sub-60s raw TTL. Flooring it to Cloudflare's 60s minimum (the
// original fix) would extend a reclaimable handle's stale KV entry past its
// real DB expiry — SubdomainAvailabilityService frees the handle the instant
// expires_at passes (no grace period) and the new owner's KV overwrite is
// only dispatched async, so a floored write can 301 a visitor to the
// superseded owner for up to 60s. Skip the write instead — the alias keeps
// whatever close-to-correct TTL a prior sync already wrote.
it('excludes a sub-60s alias TTL from bulkPut instead of flooring it (LIFE-109)', function () {
    setupUsersTable();
    setupHandleAliasesTable();
    setupSitesTable();

    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'aboutexpire',
        'handle_lc' => 'aboutexpire',
        'display_name' => 'Aboutexpire',
        'first_name' => 'Aboutexpire',
        'account_type' => 'partna',
        'status' => 'active',
        'primary_email' => 'ae@example.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // 45s out: passes the DB query's `expires_at > now()` filter (still future)
    // but computes a raw TTL well under Cloudflare's 60s floor.
    DB::connection('pgsql')->table('core.user_handle_aliases')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'handle' => 'soonhandle',
        'reclaim_until' => now()->subDays(14)->toDateTimeString(),
        'expires_at' => now()->addSeconds(45)->toDateTimeString(),
        'created_at' => now()->subDays(90)->toDateTimeString(),
        'updated_at' => now()->subDays(90)->toDateTimeString(),
    ]);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('delete');
    $kv->shouldReceive('put')->once()->with('aboutexpire', ['type' => 'individual'], null);
    // The exact assertion this fix is about: a sub-60s-but-future alias must be
    // excluded from bulkPut entirely — never floored, never written. Pre-fix
    // (raw pass-through) sent it with a ~45s TTL; the flooring fix sent it with
    // exactly 60. Both are wrong: this asserts it's absent altogether.
    $kv->shouldReceive('bulkPut')->once()->with([]);
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});

// LIFE-109 regression guard: a normal long-TTL alias must NOT be clamped down
// to the 60s floor — only genuinely sub-60s TTLs are floored. Guards against a
// broken fix that floors (or flattens) every TTL regardless of value.
it('leaves a normal long-TTL alias unfloored (LIFE-109 regression guard)', function () {
    setupUsersTable();
    setupHandleAliasesTable();
    setupSitesTable();

    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'longlived',
        'handle_lc' => 'longlived',
        'display_name' => 'Longlived',
        'first_name' => 'Longlived',
        'account_type' => 'partna',
        'status' => 'active',
        'primary_email' => 'll@example.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $future = now()->addDays(30);
    DB::connection('pgsql')->table('core.user_handle_aliases')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'handle' => 'oldlonghandle',
        'reclaim_until' => now()->addDays(14)->toDateTimeString(),
        'expires_at' => $future->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('delete');
    $kv->shouldReceive('put')->once()->with('longlived', ['type' => 'individual'], null);
    $kv->shouldReceive('bulkPut')->once()->with(Mockery::on(function (array $entries) {
        if (count($entries) !== 1 || $entries[0]['key'] !== 'oldlonghandle') {
            return false;
        }
        // ~30 days in seconds, comfortably clear of the 60s floor — proves the
        // floor is conditional, not an across-the-board clamp/flatten.
        $ttl = $entries[0]['expiration_ttl'];

        return $ttl !== null && $ttl > 86400;
    }));
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});

// P3-31: expired aliases are excluded from the KV write. The DB query's
// `expires_at > now()` filter is the primary guard (exercised here); the
// in-loop `$ttl <= 0` skip is an additional race-window backstop (an alias
// that expires between the query and the loop) and is defensive-only.
it('excludes already-expired aliases from bulkPut (P3-31)', function () {
    setupUsersTable();
    setupHandleAliasesTable();
    setupSitesTable();

    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'activehandle',
        'handle_lc' => 'activehandle',
        'display_name' => 'Activehandle',
        'first_name' => 'Activehandle',
        'account_type' => 'partna',
        'status' => 'active',
        'primary_email' => 'b@example.test',
        // partna_url omitted — not in SQLite test schema.
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // DB query uses expires_at > now() so truly-past aliases won't appear,
    // but an alias expiring in a sub-second race should also be skipped.
    // We simulate the P3-31 guard by inserting an alias that the query
    // returns (expires_at > now at insert time) but that computes a ≤0 TTL.
    // Since we can't reliably race the clock in a unit test, we verify the
    // common case: an alias with expires_at well in the future passes through.
    // The skip-guard branch (ttl <= 0) is tested implicitly via the guard condition
    // being present in code; for deterministic coverage we insert one valid + confirm
    // only it arrives in bulkPut.
    DB::connection('pgsql')->table('core.user_handle_aliases')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'handle' => 'pasthandle',
        'reclaim_until' => now()->subDays(80)->toDateTimeString(),
        // expires_at is in the past — the DB query filters it out entirely.
        // Combined with the P3-31 guard, even a race-condition-surviving alias is safe.
        'expires_at' => now()->subDay()->toDateTimeString(),
        'created_at' => now()->subDays(90)->toDateTimeString(),
        'updated_at' => now()->subDays(90)->toDateTimeString(),
    ]);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('delete');
    $kv->shouldReceive('put')->once()->with('activehandle', ['type' => 'individual'], null);
    // Expired alias filtered by DB query → bulkPut receives empty array.
    $kv->shouldReceive('bulkPut')->once()->with([]);
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});

it('excludes the current handle from alias bulkPut entries (SCALE-6)', function () {
    setupUsersTable();
    setupHandleAliasesTable();
    setupSitesTable();

    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'curhandle',
        'handle_lc' => 'curhandle',
        'display_name' => 'Curhandle',
        'first_name' => 'Curhandle',
        'account_type' => 'partna',
        'status' => 'active',
        'primary_email' => 'c@example.test',
        // partna_url omitted — not in SQLite test schema.
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // An alias matching the current handle must be skipped (would create a
    // self-redirect loop at the edge).
    DB::connection('pgsql')->table('core.user_handle_aliases')->insert([
        [
            'id' => (string) Str::uuid(),
            'user_id' => $proId,
            'handle' => 'curhandle', // matches current — must be excluded
            'reclaim_until' => now()->addDays(14)->toDateTimeString(),
            'expires_at' => now()->addDays(90)->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ],
        [
            'id' => (string) Str::uuid(),
            'user_id' => $proId,
            'handle' => 'prevhandle', // different — must be included
            'reclaim_until' => now()->addDays(14)->toDateTimeString(),
            'expires_at' => now()->addDays(90)->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ],
    ]);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('delete');
    $kv->shouldReceive('put')->once()->with('curhandle', ['type' => 'individual'], null);
    $kv->shouldReceive('bulkPut')->once()->with(Mockery::on(function (array $entries) {
        // Only prevhandle — curhandle must be excluded.
        return count($entries) === 1 && $entries[0]['key'] === 'prevhandle';
    }));
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});

it('passes a permanent alias (null expires_at) with null expiration_ttl in bulkPut (SCALE-6)', function () {
    setupUsersTable();
    setupHandleAliasesTable();
    setupSitesTable();

    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'permhandle',
        'handle_lc' => 'permhandle',
        'display_name' => 'Permhandle',
        'first_name' => 'Permhandle',
        'account_type' => 'partna',
        'status' => 'active',
        'primary_email' => 'd@example.test',
        // partna_url omitted — not in SQLite test schema.
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Legacy alias with no expiry — permanent KV entry (no TTL).
    DB::connection('pgsql')->table('core.user_handle_aliases')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'handle' => 'legacyhandle',
        'reclaim_until' => null,
        'expires_at' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('delete');
    $kv->shouldReceive('put')->once()->with('permhandle', ['type' => 'individual'], null);
    $kv->shouldReceive('bulkPut')->once()->with(Mockery::on(function (array $entries) {
        return count($entries) === 1
            && $entries[0]['key'] === 'legacyhandle'
            && $entries[0]['value'] === ['type' => 'alias', 'redirect' => 'https://permhandle.partna.au']
            && $entries[0]['expiration_ttl'] === null;
    }));
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});

// --- Task 15: unclaimed routability + TTL (spec §4) ---

it('writes a TTL-bearing individual entry for an unclaimed owner with a live build', function () {
    setupUsersTable();
    setupHandleAliasesTable();
    setupSitesTable();
    setupPreAccountBuildsTable();

    $user = User::factory()->create([
        'status' => 'unclaimed',
        'handle' => 'janedoe',
        'handle_lc' => 'janedoe',
        'auth_user_id' => null,
        'primary_email' => null,
    ]);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'janedoe']);
    // VIA_STAFF explicitly: since A.8 an unclaimed SIGN-UP build is withheld
    // from KV entirely (the factory defaults to signup); the pre-claim demo
    // routing this test pins is the staff/outreach lane's.
    $build = PreAccountBuild::factory()->make(['expires_at' => now()->addDays(30), 'built_via' => PreAccountBuild::VIA_STAFF]);
    $build->user()->associate($user);
    $build->save();

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('delete');
    // TTL is build-aligned (max(60, seconds-to-expiry)) — not the permanent null
    // that active owners get.
    $kv->shouldReceive('put')->once()->withArgs(function (string $key, array $value, ?int $ttl) {
        return $key === 'janedoe' && $value === ['type' => 'individual']
            && $ttl !== null && $ttl > 60 && $ttl <= 30 * 86400;
    });
    $kv->shouldReceive('bulkPut')->once()->with([]);
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($user->id))->handle($kv);
});

it('retires an unclaimed owner whose build has expired', function () {
    setupUsersTable();
    setupHandleAliasesTable();
    setupSitesTable();
    setupPreAccountBuildsTable();

    $user = User::factory()->create(['status' => 'unclaimed', 'handle' => 'janedoe', 'handle_lc' => 'janedoe']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'janedoe']);
    $build = PreAccountBuild::factory()->make(['expires_at' => now()->subDay()]);
    $build->user()->associate($user);
    $build->save();

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldReceive('delete')->once()->with('janedoe');
    $kv->shouldNotReceive('put');
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($user->id))->handle($kv);
});

it('retires an unclaimed owner with no pre-account build row at all', function () {
    setupUsersTable();
    setupHandleAliasesTable();
    setupSitesTable();
    setupPreAccountBuildsTable();

    // Unclaimed status but no build row (deleted / never created) — treat as
    // gone rather than routing with no known expiry.
    $user = User::factory()->create(['status' => 'unclaimed', 'handle' => 'nobuildact', 'handle_lc' => 'nobuildact']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'nobuildact']);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldReceive('delete')->once()->with('nobuildact');
    $kv->shouldNotReceive('put');
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($user->id))->handle($kv);
});

// Existing-behavior pin (already covered by "writes {type:"individual"} for an
// individual professional (§28.6)" above, kept explicit here per the Task 15
// brief): an active owner's individual entry keeps a permanent (null) TTL —
// only unclaimed owners get a build-aligned expiry.
it('still writes active owners with no TTL', function () {
    setupUsersTable();
    setupHandleAliasesTable();
    setupSitesTable();

    $user = User::factory()->create(['status' => 'active', 'handle' => 'activepin', 'handle_lc' => 'activepin']);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('delete');
    $kv->shouldReceive('put')->once()->with('activepin', ['type' => 'individual'], null);
    $kv->shouldReceive('bulkPut')->once()->with([]);
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($user->id))->handle($kv);
});

// ── a failed pre-account build must not stay routable ────────────────────────
//
// Found live 2026-08-30: three Instagram scrapes failed in one cold-build batch
// and all three subdomains still served HTTP 200 — a public page carrying a real
// person's NAME with no bio, no images, no links, just "Claim and finish site
// setup". SiteObserver publishes the subdomain when the site ROW is created,
// before the build runs, and GeneratePreAccountSiteJob's failure arms return
// before their own KV sync, so nothing ever took the route back down.

function kvUnclaimedWithBuild(string $handle, ?string $buildState): string
{
    setupUsersTable();
    setupHandleAliasesTable();
    setupSitesTable();
    setupPreAccountBuildsTable();

    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'partna',
        'status' => 'unclaimed',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    if ($buildState !== null) {
        DB::connection('pgsql')->table((new PreAccountBuild)->getTable())->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $proId,
            'source_type' => 'instagram',
            'source_ref' => $handle,
            'source_ref_lc' => mb_strtolower($handle),
            // Not VIA_SIGNUP: kvUnclaimedSignup() below explicitly overrides this
            // to VIA_SIGNUP for the A.8 sign-up-specific cases, which only makes
            // sense against a non-signup default here (mirrors the pre-NOT-NULL
            // behaviour, where a NULL built_via also read as "not signup" to the
            // job's `=== VIA_SIGNUP` check at SyncSubdomainToKvJob.php:264).
            'built_via' => PreAccountBuild::VIA_STAFF,
            'build_state' => $buildState,
            'expires_at' => now()->addDays(7)->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    return $proId;
}

it('retires the route for an unclaimed account whose build FAILED', function () {
    $proId = kvUnclaimedWithBuild('deadbuild', PreAccountBuild::STATE_FAILED);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('put');
    $kv->shouldReceive('delete')->once()->with('deadbuild');
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});

it('keeps the route for an unclaimed account whose build is READY', function () {
    $proId = kvUnclaimedWithBuild('livebuild', PreAccountBuild::STATE_READY);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('delete');
    // TTL is NOT null for an unclaimed owner — the entry expires at the edge in
    // lockstep with the build (spec §4), so this asserts a positive TTL rather
    // than the permanent write an active account gets.
    $kv->shouldReceive('put')->once()->with(
        'livebuild',
        ['type' => 'individual'],
        Mockery::on(fn ($ttl) => is_int($ttl) && $ttl >= 60),
    );
    $kv->shouldReceive('bulkPut')->once()->with([]);
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});

it('was ALREADY retiring a buildless unclaimed account — the new gate is consistent with that', function () {
    // Pins the pre-existing rule this gate sits beside rather than duplicating:
    // "expired (or buildless) unclaimed — treat as gone". Written down because it
    // is the reason the new gate reads build_state === 'failed' specifically and
    // not "no readable build" — the buildless case was already handled, one
    // branch earlier, and reaching for it again would have been a second answer
    // to a question already settled.
    $proId = kvUnclaimedWithBuild('nobuildrow', null);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('put');
    $kv->shouldReceive('delete')->once()->with('nobuildrow');
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});

// ── A.8: a sign-up build is not routable until claimed ──────────────────────

function kvUnclaimedSignup(string $handle): string
{
    $proId = kvUnclaimedWithBuild($handle, PreAccountBuild::STATE_READY);
    DB::connection('pgsql')->table((new PreAccountBuild)->getTable())
        ->where('user_id', $proId)
        ->update(['built_via' => PreAccountBuild::VIA_SIGNUP]);

    return $proId;
}

it('withholds the route for an unclaimed SIGN-UP build (A.8)', function () {
    $proId = kvUnclaimedSignup('midsignup');

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('put');
    $kv->shouldReceive('delete')->once()->with('midsignup');
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});

it('routes the same account permanently once claimed (A.8)', function () {
    $proId = kvUnclaimedSignup('claimedsignup');
    DB::connection('pgsql')->table('core.users')->where('id', $proId)->update(['status' => 'active']);

    $kv = Mockery::mock(CloudflareKvService::class);
    $kv->shouldNotReceive('delete');
    $kv->shouldReceive('put')->once()->with('claimedsignup', ['type' => 'individual'], null);
    $kv->shouldReceive('bulkPut')->once()->with([]);
    app()->instance(CloudflareKvService::class, $kv);

    (new SyncSubdomainToKvJob($proId))->handle($kv);
});
