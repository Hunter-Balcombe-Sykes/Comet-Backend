<?php

use App\Services\Site\UpdateSiteAction;
use App\Models\Core\HandleChangeLog;
use App\Models\Core\User\User;
use App\Models\Core\Site\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable();
    setupHandleAliasesTable();
    setupHandleChangeLogTable();
});

/**
 * Create a professional + site with the given subdomain using raw DB inserts
 * (no Eloquent factories; mirrors the project's test helper pattern).
 */
function createProWithSite(string $subdomain): User
{
    $proId  = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now    = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id'                => $proId,
        'auth_user_id'      => 'auth-'.Str::random(12),
        'handle'            => $subdomain,
        'handle_lc'         => strtolower($subdomain),
        'display_name'      => ucfirst($subdomain),
        'primary_email'     => $subdomain.'@example.test',
        'status'            => 'active',
        'created_at'        => $now,
        'updated_at'        => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id'             => $siteId,
        'user_id' => $proId,
        'subdomain'      => $subdomain,
        'is_published'   => 1,
        'settings'       => json_encode([]),
        'created_at'     => $now,
        'updated_at'     => $now,
    ]);

    return User::query()->with('site')->findOrFail($proId);
}

it('writes a handle_change_log row on subdomain rename with rename reason and actor', function () {
    $pro = createProWithSite('old');

    app(UpdateSiteAction::class)->execute(
        $pro->fresh(),
        ['subdomain' => 'new'],
        ['ip' => '203.0.113.4', 'user_agent' => 'pest/test']
    );

    $log = HandleChangeLog::where('user_id', $pro->id)->latest('changed_at')->firstOrFail();
    expect($log->old_handle)->toBe('old');
    expect($log->new_handle)->toBe('new');
    expect($log->reason)->toBe(HandleChangeLog::REASON_RENAME);
    expect($log->actor_id)->toBe((string) $pro->id);
    expect($log->ip_address)->toBe('203.0.113.4');
    expect($log->user_agent)->toBe('pest/test');
});

it('does not write a handle_change_log row when subdomain is unchanged', function () {
    $pro = createProWithSite('same');

    app(UpdateSiteAction::class)->execute(
        $pro->fresh(),
        ['subdomain' => 'same'],
        ['ip' => '203.0.113.4', 'user_agent' => 'pest/test']
    );

    expect(HandleChangeLog::where('user_id', $pro->id)->count())->toBe(0);
});
