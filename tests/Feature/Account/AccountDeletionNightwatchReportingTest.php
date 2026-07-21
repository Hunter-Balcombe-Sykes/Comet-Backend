<?php

use App\Models\Core\User\User;
use App\Services\User\AccountDeletionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\User\AccountDeletion\AccountDeletionTestCase;

// B10: verifies that swallowed \Throwable failures on the deletion / GDPR-PII-erasure
// path now surface to Nightwatch via report($e), not just Log::error(...).
//
// Before the fix: caught + Log::error only — Nightwatch blind (an operator would
// never see a sustained storage/DB fault degrading account deletion or GDPR erasure).
// After: report($e) alongside the existing log line. Fault tolerance (swallow +
// continue) is unchanged — purge() must still return true and request() must still
// return its 503 error shape.

it('reports a purge()-step failure to Nightwatch while purge() still completes (LIFE-5)', function () {
    AccountDeletionTestCase::boot();

    config([
        'supabase.url' => 'https://test.supabase.co',
        'supabase.service_role_key' => 'test-key',
        // Point purgeExportZips at a disk with no configured driver at all —
        // Storage::disk() throws InvalidArgumentException, exercising the outer
        // catch this unit adds report($e) to.
        'partna.media_disk' => 'nonexistent_test_disk_xyz',
    ]);

    Http::fake(['https://test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);
    Exceptions::fake();

    $id = (string) Str::uuid();
    $authId = (string) Str::uuid();
    $handle = 'nw-'.substr($id, 0, 6);

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'auth_user_id' => $authId,
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => 'Nightwatch Test User',
        'primary_email' => "deleted+{$id}@partna.au",
        'status' => 'pending_deletion',
        'deletion_confirmed_at' => now()->subDays(31)->toIso8601String(),
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    // resolveDeletedAccountEmail() reads this snapshot for the pre-pseudonymisation email.
    DB::connection('pgsql')->table('audit.user_deletion_audit')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $id,
        'professional_handle_snapshot' => $handle,
        'professional_email_snapshot' => 'original@example.com',
        'event' => 'confirmed',
        'actor_type' => 'professional',
        'created_at' => now()->subDays(31)->toIso8601String(),
    ]);

    $professional = User::find($id);

    $result = app(AccountDeletionService::class)->purge($professional);

    // Fault tolerance intact: one PII-erasure step throwing must not block the hard delete.
    expect($result)->toBeTrue();

    Exceptions::assertReported(InvalidArgumentException::class);
});

it('reports a request()-transaction failure to Nightwatch (LIFE-1)', function () {
    // Minimal isolated schema (deliberately NOT AccountDeletionTestCase::boot() —
    // this test only needs core.users and needs it broken in a specific way).
    config(['database.default' => 'sqlite']);
    config(['database.connections.pgsql' => array_merge(
        config('database.connections.sqlite'),
        ['database' => ':memory:'],
    )]);

    DB::purge('pgsql');
    DB::reconnect('pgsql');

    $conn = DB::connection('pgsql');
    try {
        $conn->statement('ATTACH DATABASE \':memory:\' AS core');
    } catch (Throwable) {
    }

    // Deliberately omits deletion_mail_sent_at, which request()'s update() call
    // inside the transaction writes to — forces a QueryException from inside the
    // try block this unit adds report($e) to.
    $conn->statement('CREATE TABLE core.users (
        id TEXT PRIMARY KEY,
        auth_user_id TEXT,
        handle TEXT,
        primary_email TEXT,
        status TEXT DEFAULT "active",
        deletion_token_hash TEXT,
        deletion_requested_at TEXT,
        deleted_at TEXT
    )');

    $id = (string) Str::uuid();

    $conn->table('core.users')->insert([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'nw-req',
        'primary_email' => 'nwreq@example.com',
        'status' => 'active',
    ]);

    Exceptions::fake();

    $professional = User::find($id);
    $result = app(AccountDeletionService::class)->request($professional, Request::create('/', 'POST'));

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(503);

    Exceptions::assertReported(QueryException::class);
});
