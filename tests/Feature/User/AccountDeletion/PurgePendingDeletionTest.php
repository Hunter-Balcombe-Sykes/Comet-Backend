<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\User\User;
use App\Models\Core\User\UserDeletionAuditEntry;
use App\Services\User\AccountDeletionService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\User\AccountDeletion\AccountDeletionTestCase;

beforeEach(function () {
    AccountDeletionTestCase::boot();
});

function seedPurgeableUser(array $overrides = []): User
{
    $id = (string) Str::uuid();
    $authId = (string) Str::uuid();
    $data = array_merge([
        'id' => $id,
        'auth_user_id' => $authId,
        'handle' => 'purge-'.substr($id, 0, 6),
        'handle_lc' => 'purge-'.substr($id, 0, 6),
        'display_name' => 'To Purge',
        'primary_email' => 'purge-'.substr($id, 0, 6).'@example.com',
        'status' => 'pending_deletion',
        'deletion_confirmed_at' => now()->subDays(31)->toIso8601String(),
    ], $overrides);

    DB::connection('pgsql')->table('core.users')->insert($data);

    return User::query()->where('id', $id)->first();
}

it('calls Supabase Admin API and hard-deletes professional on success', function () {
    $pro = seedPurgeableUser();

    Http::fake([
        'test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200),
    ]);

    $service = new AccountDeletionService;
    $result = $service->purge($pro);

    expect($result)->toBeTrue();

    $stillExists = DB::connection('pgsql')->table('core.users')
        ->where('id', $pro->id)->exists();
    expect($stillExists)->toBeFalse();

    Http::assertSent(function ($request) use ($pro) {
        return $request->method() === 'DELETE'
            && str_contains($request->url(), "/auth/v1/admin/users/{$pro->auth_user_id}");
    });
});

it('treats Supabase 404 as success and still hard-deletes professional', function () {
    $pro = seedPurgeableUser();

    Http::fake([
        'test.supabase.co/auth/v1/admin/users/*' => Http::response(['message' => 'User not found'], 404),
    ]);

    $service = new AccountDeletionService;
    $result = $service->purge($pro);

    expect($result)->toBeTrue();

    $stillExists = DB::connection('pgsql')->table('core.users')
        ->where('id', $pro->id)->exists();
    expect($stillExists)->toBeFalse();
});

it('skips hard delete and logs purge_failed when Supabase returns 500', function () {
    $pro = seedPurgeableUser();

    Http::fake([
        'test.supabase.co/auth/v1/admin/users/*' => Http::response(['message' => 'server error'], 500),
    ]);

    $service = new AccountDeletionService;
    $result = $service->purge($pro);

    expect($result)->toBeFalse();

    $stillExists = DB::connection('pgsql')->table('core.users')
        ->where('id', $pro->id)->exists();
    expect($stillExists)->toBeTrue();

    $audit = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('event', 'purge_failed')
        ->where('user_id', $pro->id)
        ->first();
    expect($audit)->not->toBeNull();
});

it('writes purged audit row with handle + email snapshots', function () {
    $pro = seedPurgeableUser(['handle' => 'snapshot-me', 'primary_email' => 'snapshot@example.com']);

    Http::fake([
        'test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200),
    ]);

    $service = new AccountDeletionService;
    $service->purge($pro);

    $audit = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('event', 'purged')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->professional_handle_snapshot)->toBe('snapshot-me')
        ->and($audit->professional_email_snapshot)->toBe('snapshot@example.com')
        ->and($audit->user_id)->toBeNull(); // professional is deleted, FK set null
});

it('purged audit row records the resolved pre-pseudonymisation email, not the placeholder (SEM-1)', function () {
    // By purge time (30 days post-confirmation) primary_email is already the
    // "deleted+{id}@partna.au" placeholder that executeConfirmation() wrote; the
    // real address survives only in the EVENT_CONFIRMED audit snapshot captured
    // before pseudonymisation. purge() must resolve THAT for the PURGED receipt.
    $pro = seedPurgeableUser();
    $realEmail = 'real-'.substr($pro->id, 0, 6).'@example.com';

    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)
        ->update(['primary_email' => 'deleted+'.$pro->id.'@partna.au']);

    UserDeletionAuditEntry::forceCreate([
        'user_id' => $pro->id,
        'professional_handle_snapshot' => $pro->handle,
        'professional_email_snapshot' => $realEmail,
        'event' => UserDeletionAuditEntry::EVENT_CONFIRMED,
        'actor_type' => UserDeletionAuditEntry::ACTOR_TYPE_PROFESSIONAL,
    ]);

    $pro->refresh(); // in-memory model must carry the pseudonymised email

    Http::fake([
        'test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200),
    ]);

    (new AccountDeletionService)->purge($pro);

    $purged = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('event', 'purged')->first();

    // Without the SEM-1 fix this would be the "deleted+{id}@partna.au" placeholder.
    expect($purged)->not->toBeNull()
        ->and($purged->professional_email_snapshot)->toBe($realEmail)
        ->and($purged->professional_email_snapshot)->not->toContain('deleted+');
});

it('command purges professionals past 30 days but skips within grace', function () {
    AccountDeletionTestCase::boot(); // re-init DB for command-level test

    Http::fake([
        'test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200),
    ]);

    // Past grace — should be purged
    $purgeable = seedPurgeableUser([
        'deletion_confirmed_at' => now()->subDays(31)->toIso8601String(),
    ]);

    // Within grace — should be skipped
    $withinGrace = seedPurgeableUser([
        'deletion_confirmed_at' => now()->subDays(5)->toIso8601String(),
    ]);

    Artisan::call('partna:purge-soft-deletes');

    $purgeableExists = DB::connection('pgsql')->table('core.users')
        ->where('id', $purgeable->id)->exists();
    $withinGraceExists = DB::connection('pgsql')->table('core.users')
        ->where('id', $withinGrace->id)->exists();

    expect($purgeableExists)->toBeFalse()
        ->and($withinGraceExists)->toBeTrue();
});

it('dispatches a custom-domain KV retirement when purging a user with an active custom domain (EDGE-1)', function () {
    Bus::fake();

    $pro = seedPurgeableUser();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'subdomain' => $pro->handle,
        'is_published' => 0,
        'custom_domain' => 'purged.example',
        'custom_domain_status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);

    (new AccountDeletionService)->purge($pro);

    Bus::assertDispatched(SyncSubdomainToKvJob::class, function (SyncSubdomainToKvJob $job) use ($pro) {
        return $job->userId === $pro->id && $job->retireCustomDomain === 'purged.example';
    });
});

it('does not dispatch a custom-domain retirement when purging a user without an active domain (EDGE-1 guard)', function () {
    Bus::fake();

    $pro = seedPurgeableUser();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'subdomain' => $pro->handle,
        'is_published' => 0,
        'custom_domain' => null,
        'custom_domain_status' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);

    (new AccountDeletionService)->purge($pro);

    Bus::assertNotDispatched(SyncSubdomainToKvJob::class, function (SyncSubdomainToKvJob $job) {
        return $job->retireCustomDomain !== null;
    });
});

it('dispatches CloudflareCachePurgeJob with the captured handle snapshot on purge (EDGE-1)', function () {
    Bus::fake();

    $pro = seedPurgeableUser();

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);

    (new AccountDeletionService)->purge($pro);

    Bus::assertDispatched(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $job) use ($pro) {
        return $job->handle === $pro->handle;
    });
});

it('dispatches the purge as a primary (non-follow-up) job, so its unconditional follow-up schedule still fires (EDGE-1)', function () {
    // This is the actual mitigation for the KV-retire/purge completion-order race
    // (see the dispatch-site comment in AccountDeletionService::purge()):
    // CloudflareCachePurgeJob::handle() only schedules its follow-ups when
    // `! $this->followUp`. If this dispatch were ever changed to pass
    // followUp: true, the 120s/300s/900s backstop that evicts a re-warmed
    // cache entry would silently stop firing on the deletion path. Pin the
    // precondition here; the follow-up scheduling mechanics themselves are
    // covered by tests/Unit/Jobs/CloudflareCachePurgeJobTest.php.
    Bus::fake();

    $pro = seedPurgeableUser();

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);

    (new AccountDeletionService)->purge($pro);

    Bus::assertDispatched(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $job) {
        return $job->followUp === false && $job->moderationCaseId === null && $job->bulk === false;
    });
});

it('carries the active custom domain through to the edge cache purge job (EDGE-1)', function () {
    Bus::fake();

    $pro = seedPurgeableUser();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'subdomain' => $pro->handle,
        'is_published' => 0,
        'custom_domain' => 'purged.example',
        'custom_domain_status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);

    (new AccountDeletionService)->purge($pro);

    Bus::assertDispatched(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $job) {
        return $job->customDomain === 'purged.example';
    });
});

it('still dispatches the edge cache purge with a null custom domain when none is active (EDGE-1 guard)', function () {
    Bus::fake();

    $pro = seedPurgeableUser();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'subdomain' => $pro->handle,
        'is_published' => 0,
        'custom_domain' => null,
        'custom_domain_status' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);

    (new AccountDeletionService)->purge($pro);

    // Must not be hung off the $retireCustomDomain condition — the common
    // (no-custom-domain) case would otherwise silently lose its edge purge.
    Bus::assertDispatched(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $job) use ($pro) {
        return $job->handle === $pro->handle && $job->customDomain === null;
    });
});

it('does not dispatch the edge cache purge for a handle-less user (EDGE-1 guard)', function () {
    Bus::fake();

    $pro = seedPurgeableUser(['handle' => null, 'handle_lc' => null]);

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);

    (new AccountDeletionService)->purge($pro);

    Bus::assertNotDispatched(CloudflareCachePurgeJob::class);
});

it('enqueues the KV retire before the edge cache purge, dispatch order only (EDGE-1)', function () {
    // Queue::fake() records DISPATCH (push) order, not completion order — with
    // supervisor-1 at maxProcesses=>2 (config/horizon.php, both production and
    // development), two workers can dequeue these in this order and still
    // COMPLETE out of order, so this assertion is not proof the KV entry is
    // retired before the edge cache purge finishes. That narrow race is instead
    // closed (to ~120s, not eliminated) by CloudflareCachePurgeJob's own
    // unconditional follow-up schedule — see the dispatch-site comment in
    // AccountDeletionService::purge() and the follow-up mechanics pinned by
    // tests/Unit/Jobs/CloudflareCachePurgeJobTest.php. This test only pins the
    // cheap, genuinely-provable fact: the KV retire is pushed first.
    Queue::fake();

    $pro = seedPurgeableUser();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'subdomain' => $pro->handle,
        'is_published' => 0,
        'custom_domain' => 'purged.example',
        'custom_domain_status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);

    (new AccountDeletionService)->purge($pro);

    $pushed = array_keys(Queue::pushedJobs());
    expect($pushed)->toBe([SyncSubdomainToKvJob::class, CloudflareCachePurgeJob::class]);
});

// ── LIFE-102 / LIFE-103: purge-step failure logs must carry user_id ────────
//
// Both steps are keyed on email_lc, not user_id, so their target row carries
// no user identifier of its own — the acting user_id has to be logged
// explicitly or a failure here is unattributable in the log stream. Each
// step's target table is dropped to force the query inside its try/catch to
// throw; the rest of purge() (an independent, per-step try/catch pipeline)
// still runs to completion around it.

it('logs user_id when early access signup erasure fails (LIFE-102)', function () {
    $pro = seedPurgeableUser();

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);

    DB::connection('pgsql')->statement('DROP TABLE core.early_access_signups');

    Log::spy();

    (new AccountDeletionService)->purge($pro);

    Log::shouldHaveReceived('error')
        ->withArgs(function ($message, $context) use ($pro) {
            return $message === 'Early access signup erasure failed during account purge'
                && ($context['user_id'] ?? null) === $pro->id;
        })
        ->once();
});

it('logs user_id when global email subscription erasure fails (LIFE-103)', function () {
    $pro = seedPurgeableUser();

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);

    DB::connection('pgsql')->statement('DROP TABLE notifications.email_subscriptions');

    Log::spy();

    (new AccountDeletionService)->purge($pro);

    Log::shouldHaveReceived('error')
        ->withArgs(function ($message, $context) use ($pro) {
            return $message === 'Global email subscription erasure failed during account purge'
                && ($context['user_id'] ?? null) === $pro->id;
        })
        ->once();
});

it('purges to completion on the SQLite test driver without invoking the pgsql-only helper', function () {
    // Proves the driver guard holds: if purge() called audit.null_user_audit_links()
    // unconditionally, SQLite would throw "no such function" here.
    $pro = seedPurgeableUser();

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);

    expect((new AccountDeletionService)->purge($pro))->toBeTrue();
    expect(DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->exists())->toBeFalse();
});

it('does not hard-delete the row when the Supabase auth-delete fails (retryable)', function () {
    $pro = seedPurgeableUser();

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 500)]);

    expect((new AccountDeletionService)->purge($pro))->toBeFalse();
    expect(DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->exists())->toBeTrue();
});

// ── COV-TIMEOUT: bounded HTTP call to GoTrue's admin delete ────────────────

it('bounds the Supabase auth-delete with a configured timeout + clamped connect timeout (COV-TIMEOUT)', function () {
    // Positive control: Http::fake's callback form receives the raw Guzzle
    // transfer options as the 2nd arg — the only place PendingRequest::timeout()/
    // connectTimeout() are observable, since they aren't part of the PSR request
    // Http::recorded() exposes. Drive the config (not the default) and assert the
    // driven value — asserting the default 5 would still pass against a
    // hardcoded ->timeout(5), which is the exact vacuity this test exists to rule out.
    Config::set('supabase.http_timeout_seconds', 7);

    $pro = seedPurgeableUser();

    $capturedOptions = [];
    Http::fake(function ($request, $options) use (&$capturedOptions) {
        $capturedOptions[] = $options;

        return Http::response('', 200);
    });

    expect((new AccountDeletionService)->purge($pro))->toBeTrue();

    expect($capturedOptions)->toHaveCount(1)
        ->and($capturedOptions[0]['timeout'] ?? null)->toBe(7)
        ->and($capturedOptions[0]['connect_timeout'] ?? null)->toBe(3);
});

it('clamps the connect timeout to a configured total budget shorter than 3s (COV-TIMEOUT)', function () {
    // min(3, $timeout), not a bare 3 — an incident-time
    // SUPABASE_HTTP_TIMEOUT_SECONDS=2 must not let connectTimeout exceed the
    // total budget.
    Config::set('supabase.http_timeout_seconds', 2);

    $pro = seedPurgeableUser();

    $capturedOptions = [];
    Http::fake(function ($request, $options) use (&$capturedOptions) {
        $capturedOptions[] = $options;

        return Http::response('', 200);
    });

    (new AccountDeletionService)->purge($pro);

    expect($capturedOptions[0]['timeout'] ?? null)->toBe(2)
        ->and($capturedOptions[0]['connect_timeout'] ?? null)->toBe(2);
});

it('treats a Supabase connection failure as a retryable purge failure, not an uncaught exception (COV-TIMEOUT)', function () {
    // Regression control: a black-holed/degraded GoTrue host must funnel into
    // the same failure branch a non-2xx response takes — EVENT_PURGE_FAILED
    // audit row, report() to Nightwatch, false return — not propagate as an
    // uncaught ConnectionException.
    $pro = seedPurgeableUser();

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => fn () => throw new ConnectionException('timed out')]);

    expect((new AccountDeletionService)->purge($pro))->toBeFalse();

    expect(DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->exists())->toBeTrue();

    $audit = DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('event', 'purge_failed')
        ->where('user_id', $pro->id)
        ->first();
    expect($audit)->not->toBeNull();
});

it('command continues past a Supabase connection failure to purge the next row (COV-TIMEOUT)', function () {
    // Guards the nightly-batch impact: PurgeSoftDeleted::purgePendingDeletionProfessionals()
    // has no try/catch around the purge() call inside its chunk() loop, so an
    // uncaught ConnectionException here would abort the entire chunk — not just
    // skip the one bad row. This proves the catch keeps the batch alive.
    AccountDeletionTestCase::boot();

    $failing = seedPurgeableUser();
    $succeeding = seedPurgeableUser();

    Http::fake([
        "test.supabase.co/auth/v1/admin/users/{$failing->auth_user_id}" => fn () => throw new ConnectionException('timed out'),
        'test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200),
    ]);

    Artisan::call('partna:purge-soft-deletes');

    expect(DB::connection('pgsql')->table('core.users')->where('id', $failing->id)->exists())->toBeTrue()
        ->and(DB::connection('pgsql')->table('core.users')->where('id', $succeeding->id)->exists())->toBeFalse();
});
