<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('adds moderation_state column to site.sites with default active', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('information_schema queries require PostgreSQL.');
    }

    $col = DB::selectOne(<<<'SQL'
        SELECT column_name, is_nullable, column_default
        FROM information_schema.columns
        WHERE table_schema = 'site' AND table_name = 'sites'
          AND column_name = 'moderation_state'
    SQL);

    expect($col)->not->toBeNull();
    expect($col->is_nullable)->toBe('NO');
    expect($col->column_default)->toContain("'active'");
})->group('postgres');

it('rejects illegal site moderation_state values via CHECK constraint', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Constraint tests require PostgreSQL.');
    }

    // Pick any existing site via the test factory once SiteFactory exists (Task 7.5).
    // Until then, this assertion runs against a forceFilled row.
    $user = User::factory()->create();
    $site = (new Site)->forceFill([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => 'check-'.uniqid(),
        'skeleton_id' => 'skeleton-1',
        'settings' => [],
        'is_published' => true,
    ]);
    $site->save();

    expect(fn () => DB::statement(
        "UPDATE site.sites SET moderation_state = 'invalid_state' WHERE id = ?",
        [$site->id]
    ))->toThrow(QueryException::class);
})->group('postgres');

it('users.status already covers moderation outcomes (no new column needed)', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('pg_constraint queries require PostgreSQL.');
    }

    // Sanity check: confirm the values we'll be using exist in users_status_check.
    $constraintDef = DB::selectOne(<<<'SQL'
        SELECT pg_get_constraintdef(oid) AS def
        FROM pg_constraint
        WHERE conname = 'users_status_check'
    SQL)->def ?? '';

    expect($constraintDef)->toContain('active');
    expect($constraintDef)->toContain('suspended');
    expect($constraintDef)->toContain('disabled');
})->group('postgres');
