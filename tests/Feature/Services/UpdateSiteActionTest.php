<?php

// TEST-1: Coverage for UpdateSiteAction::execute() — the ~150-line subdomain /
// alias / handle / publish business core. The cooldown and collision guards run
// their own DB queries that bypass the FormRequest layer, so these tests drive
// the action directly (not through HTTP) to exercise them. UpdateSiteActionLifecycleTest
// already covers the reclaim/expiry stamping (todo) and one rename-back case; this
// file covers the remaining branches: rename side-effects, cooldown, both collision
// paths, handle sync, rename-back collapse, the publish guard, and settings merge.

use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheService;
use App\Services\Site\UpdateSiteAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable();
    setupHandleAliasesTable();
    setupHandleChangeLogTable();

    // Stub the cache service so the SiteObserver's invalidateSite() on save is a
    // no-op (no Redis / no public_site_payload view needed), and fake the queue
    // so the observer's KV-sync / cache-warm dispatches don't run.
    $cache = Mockery::mock(SiteCacheService::class)->shouldIgnoreMissing();
    app()->instance(SiteCacheService::class, $cache);
    Queue::fake();

    Carbon::setTestNow('2026-06-03 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Insert a professional + their site and return the freshly-loaded User with
 * the site relation eager-loaded (the action calls loadMissing('site')).
 */
function makeSiteOwner(array $userOverrides = [], array $siteOverrides = []): User
{
    $proId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert(array_merge([
        'id' => $proId,
        'handle' => 'alpha',
        'handle_lc' => 'alpha',
        'display_name' => 'Alpha Pro',
        'primary_email' => 'alpha@example.test',
        'account_type' => 'individual',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ], $userOverrides));

    DB::connection('pgsql')->table('site.sites')->insert(array_merge([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'subdomain' => 'alpha',
        'is_published' => 0,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ], $siteOverrides));

    return User::query()->with('site')->findOrFail($proId);
}

it('renames the subdomain: writes the alias, syncs the handle, logs the change, and advances the cooldown clock', function () {
    $pro = makeSiteOwner();
    $siteId = $pro->site->id;

    app(UpdateSiteAction::class)->execute($pro, ['subdomain' => 'bravo']);

    $site = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->first();
    expect($site->subdomain)->toBe('bravo')
        ->and($site->subdomain_changed_at)->not->toBeNull();

    // Old subdomain preserved as a redirect alias for this site.
    expect(DB::connection('pgsql')->table('site.site_subdomain_aliases')
        ->where('site_id', $siteId)->where('subdomain', 'alpha')->exists())->toBeTrue();

    // Canonical handle on the professional follows the subdomain, old handle aliased.
    expect(DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->value('handle'))->toBe('bravo');
    expect(DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->value('handle_lc'))->toBe('bravo');
    expect(DB::connection('pgsql')->table('core.user_handle_aliases')
        ->where('user_id', $pro->id)->where('handle', 'alpha')->exists())->toBeTrue();

    // Audit trail records the rename.
    $log = DB::connection('pgsql')->table('audit.handle_change_log')->where('user_id', $pro->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->old_handle)->toBe('alpha')
        ->and($log->new_handle)->toBe('bravo');
});

it('blocks a second subdomain change inside the 30-day cooldown window', function () {
    // Last changed 5 days ago — well inside the 30-day cooldown.
    $pro = makeSiteOwner(siteOverrides: ['subdomain_changed_at' => now()->subDays(5)->toDateTimeString()]);

    try {
        app(UpdateSiteAction::class)->execute($pro, ['subdomain' => 'charlie']);
        $this->fail('Expected a ValidationException for the cooldown window.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('subdomain');
    }

    // Subdomain unchanged.
    expect(DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('subdomain'))->toBe('alpha');
});

it('rejects a subdomain already taken by another site', function () {
    $pro = makeSiteOwner();

    // A different professional already owns 'taken'.
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => (string) Str::uuid(),
        'subdomain' => 'taken',
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    try {
        app(UpdateSiteAction::class)->execute($pro, ['subdomain' => 'taken']);
        $this->fail('Expected a ValidationException for the site collision.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('subdomain');
    }
});

it('rejects a subdomain held as another site\'s redirect alias', function () {
    $pro = makeSiteOwner();

    // Another site holds 'reserved' as a historical alias.
    DB::connection('pgsql')->table('site.site_subdomain_aliases')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => (string) Str::uuid(),
        'subdomain' => 'reserved',
        'created_at' => now()->toDateTimeString(),
    ]);

    try {
        app(UpdateSiteAction::class)->execute($pro, ['subdomain' => 'reserved']);
        $this->fail('Expected a ValidationException for the alias collision.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('subdomain');
    }
});

it('collapses the redirect alias when renaming back to a subdomain the site already holds', function () {
    $pro = makeSiteOwner();
    $siteId = $pro->site->id;

    // This site already holds 'bravo' as a redirect alias (e.g. a prior rename).
    DB::connection('pgsql')->table('site.site_subdomain_aliases')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'subdomain' => 'bravo',
        'created_at' => now()->toDateTimeString(),
    ]);

    app(UpdateSiteAction::class)->execute($pro, ['subdomain' => 'bravo']);

    // Renaming back collapses the 'bravo' alias (owned again, nothing to redirect)
    // and the vacated 'alpha' becomes the new alias.
    expect(DB::connection('pgsql')->table('site.site_subdomain_aliases')
        ->where('site_id', $siteId)->where('subdomain', 'bravo')->exists())->toBeFalse();
    expect(DB::connection('pgsql')->table('site.site_subdomain_aliases')
        ->where('site_id', $siteId)->where('subdomain', 'alpha')->exists())->toBeTrue();
});

it('refuses to publish a site whose professional has no display name', function () {
    $pro = makeSiteOwner(userOverrides: ['display_name' => null]);

    try {
        app(UpdateSiteAction::class)->execute($pro, ['is_published' => true]);
        $this->fail('Expected a ValidationException for the publish-readiness guard.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('is_published');
    }

    expect((bool) DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('is_published'))->toBeFalse();
});

it('merges settings PATCH-style, preserving unknown keys and stripping the dead design path', function () {
    $pro = makeSiteOwner(siteOverrides: [
        'settings' => json_encode(['keep_me' => 'yes', 'booking_mode' => 'manual']),
    ]);

    app(UpdateSiteAction::class)->execute($pro, [
        'settings' => [
            'booking_mode' => 'none', // promoted key — goes to column, stripped from JSONB
            'extra' => 'z',            // unknown key — must survive in JSONB
            'design' => ['accent' => '#fff'], // dead path — must be stripped
        ],
    ]);

    $row = DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->first();
    $settings = json_decode($row->settings, true) ?? [];

    // Non-promoted keys survive in the JSONB; design path is stripped.
    expect($settings)->toMatchArray([
        'keep_me' => 'yes',
        'extra' => 'z',
    ])->and($settings)->not->toHaveKey('design');

    // Phase 2 strip: booking_mode is a promoted key → column only, not in JSONB.
    expect($settings)->not->toHaveKey('booking_mode');
    expect($row->booking_mode)->toBe('none');
});
