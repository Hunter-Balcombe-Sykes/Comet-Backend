<?php

use App\Models\Core\Site\Site;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Schema\Concerns\SeedsAuthUsers;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class, SeedsAuthUsers::class)->in(__FILE__);

it('adds moderation_state column to site.sites with default active', function () {
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
    // Pick any existing site via the test factory once SiteFactory exists (Task 7.5).
    // Until then, this assertion runs against a forceFilled row.
    $user = $this->seedAuthUser();

    try {
        $site = (new Site)->forceFill([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'subdomain' => 'check-'.uniqid(),
            'architecture_id' => 'staple',
            'settings' => [],
            'is_published' => true,
        ]);
        $site->save();

        expect(fn () => DB::statement(
            "UPDATE site.sites SET moderation_state = 'invalid_state' WHERE id = ?",
            [$site->id]
        ))->toThrow(QueryException::class);
    } finally {
        // No RefreshDatabase in this lane — the DB is persistent and shared
        // across the whole run. forceDelete cascades site.sites via its FK.
        $this->cleanupSeededUser($user);
    }
})->group('postgres');

it('users.status already covers moderation outcomes (no new column needed)', function () {
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
