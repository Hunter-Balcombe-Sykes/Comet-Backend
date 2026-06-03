<?php

use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffUserController;
use App\Models\Core\User\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $conn = DB::connection('pgsql');
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS core");
    } catch (Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.users (
        id TEXT PRIMARY KEY,
        handle TEXT,
        display_name TEXT,
        professional_type TEXT,
        account_type TEXT NULL,
        status TEXT,
        admin_notes TEXT,
        about TEXT,
        deleted_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

function seedUsers(int $n, string $status = 'active'): array
{
    $ids = [];
    for ($i = 0; $i < $n; $i++) {
        $id = (string) Str::uuid();
        DB::table('core.users')->insert([
            'id' => $id,
            'handle' => "bulk-{$i}",
            'display_name' => "Bulk Pro {$i}",
            'status' => $status,
        ]);
        $ids[] = $id;
    }

    return $ids;
}

it('bulk-suspends a wave of professionals', function () {
    $ids = seedUsers(5);

    $controller = new StaffUserController;
    $request = Request::create('/', 'POST', [
        'ids' => $ids,
        'status' => 'suspended',
    ]);
    // Fresh TOTP amr entry — satisfies the requiresFreshAal2 gate added in B17.
    $request->attributes->set('supabase_amr', [['method' => 'totp', 'timestamp' => time()]]);

    Log::spy();
    $response = $controller->bulkUpdateStatus($request);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['updated_count'])->toBe(5)
        ->and($data['status'])->toBe('suspended')
        ->and($data['missing_ids'])->toBe([]);

    // Every row should now be suspended
    expect(User::query()->whereIn('id', $ids)->where('status', 'suspended')->count())->toBe(5);

    // One audit log per professional
    Log::shouldHaveReceived('info')->times(5);
});

it('accepts exactly 100 IDs', function () {
    $ids = seedUsers(100);

    $controller = new StaffUserController;
    $request = Request::create('/', 'POST', [
        'ids' => $ids,
        'status' => 'suspended',
    ]);
    $request->attributes->set('supabase_amr', [['method' => 'totp', 'timestamp' => time()]]);

    $response = $controller->bulkUpdateStatus($request);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['updated_count'])->toBe(100);
});

it('rejects 101 IDs with validation error', function () {
    $ids = array_map(fn () => (string) Str::uuid(), range(1, 101));

    $controller = new StaffUserController;
    $request = Request::create('/', 'POST', [
        'ids' => $ids,
        'status' => 'suspended',
    ]);
    $request->attributes->set('supabase_amr', [['method' => 'totp', 'timestamp' => time()]]);

    expect(fn () => $controller->bulkUpdateStatus($request))
        ->toThrow(ValidationException::class);
});

it('rejects unknown status values', function () {
    $controller = new StaffUserController;
    $request = Request::create('/', 'POST', [
        'ids' => [(string) Str::uuid()],
        'status' => 'banned',
    ]);
    $request->attributes->set('supabase_amr', [['method' => 'totp', 'timestamp' => time()]]);

    expect(fn () => $controller->bulkUpdateStatus($request))
        ->toThrow(ValidationException::class);
});

it('rejects non-UUID IDs', function () {
    $controller = new StaffUserController;
    $request = Request::create('/', 'POST', [
        'ids' => ['not-a-uuid'],
        'status' => 'suspended',
    ]);
    $request->attributes->set('supabase_amr', [['method' => 'totp', 'timestamp' => time()]]);

    expect(fn () => $controller->bulkUpdateStatus($request))
        ->toThrow(ValidationException::class);
});

it('returns missing_ids for unknown UUIDs without rolling back valid ones', function () {
    $valid = seedUsers(2);
    $missing = [(string) Str::uuid(), (string) Str::uuid()];

    $controller = new StaffUserController;
    $request = Request::create('/', 'POST', [
        'ids' => array_merge($valid, $missing),
        'status' => 'suspended',
    ]);
    $request->attributes->set('supabase_amr', [['method' => 'totp', 'timestamp' => time()]]);

    $response = $controller->bulkUpdateStatus($request);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['updated_count'])->toBe(2)
        ->and(count($data['missing_ids']))->toBe(2);
});

it('rejects empty ids array', function () {
    $controller = new StaffUserController;
    $request = Request::create('/', 'POST', [
        'ids' => [],
        'status' => 'suspended',
    ]);
    $request->attributes->set('supabase_amr', [['method' => 'totp', 'timestamp' => time()]]);

    expect(fn () => $controller->bulkUpdateStatus($request))
        ->toThrow(ValidationException::class);
});
