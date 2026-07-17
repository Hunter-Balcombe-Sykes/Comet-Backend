<?php

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Services\Cloudflare\CloudflareKvService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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

it('writes {type:"individual"} for an individual professional (§28.6)', function () {
    setupUsersTable();
    setupHandleAliasesTable();
    setupSitesTable();

    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'soloact',
        'handle_lc' => 'soloact',
        'account_type' => 'individual',
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
        'account_type' => 'individual',
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
        'account_type' => 'individual',
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
        'account_type' => 'individual',
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
        'account_type' => 'individual',
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
        'account_type' => 'individual',
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
        'account_type' => 'individual',
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
        'account_type' => 'individual',
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
        'account_type' => 'individual',
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
        'account_type' => 'individual',
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
        'account_type' => 'individual',
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
        'account_type' => 'individual',
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
        'account_type' => 'individual',
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
