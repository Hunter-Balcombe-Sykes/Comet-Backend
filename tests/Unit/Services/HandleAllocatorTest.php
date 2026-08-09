<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\User\HandleAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    // SIGNUP-1: allocate() now reads site.sites + site.site_subdomain_aliases too.
    setupSitesTable();
});

it('slugs the seed and suffixes bare integers on collision (bootstrap-identical)', function () {
    $alloc = app(HandleAllocator::class);

    expect($alloc->allocate('Jane Doe'))->toBe(['handle' => 'jane-doe', 'handle_lc' => 'jane-doe']);

    User::factory()->create(['handle' => 'jane-doe', 'handle_lc' => 'jane-doe']);
    expect($alloc->allocate('Jane Doe'))->toBe(['handle' => 'jane-doe1', 'handle_lc' => 'jane-doe1']);
});

it('falls back to "professional" for an empty slug', function () {
    expect(app(HandleAllocator::class)->allocate('$$$')['handle'])->toBe('professional');
});

it('treats a subdomain held by another site as unavailable', function () {
    $other = User::factory()->create(['handle' => 'other', 'handle_lc' => 'other']);
    Site::factory()->create(['user_id' => $other->id, 'subdomain' => 'jane-doe']);

    expect(app(HandleAllocator::class)->allocate('Jane Doe')['handle'])->toBe('jane-doe1');
});

it('treats a subdomain held by an active alias as unavailable, but not an expired one', function () {
    $other = User::factory()->create(['handle' => 'other', 'handle_lc' => 'other']);
    $site = Site::factory()->create(['user_id' => $other->id, 'subdomain' => 'other']);

    $alias = ['id' => (string) Str::uuid(), 'site_id' => $site->id, 'subdomain' => 'jane-doe', 'created_at' => now()];

    DB::connection('pgsql')->table('site.site_subdomain_aliases')
        ->insert($alias + ['expires_at' => now()->addDays(90)]);
    expect(app(HandleAllocator::class)->allocate('Jane Doe')['handle'])->toBe('jane-doe1');

    DB::connection('pgsql')->table('site.site_subdomain_aliases')
        ->where('id', $alias['id'])->update(['expires_at' => now()->subDay()]);
    expect(app(HandleAllocator::class)->allocate('Jane Doe')['handle'])->toBe('jane-doe');
});

it('never allocates a reserved subdomain', function () {
    expect(app(HandleAllocator::class)->allocate('www')['handle'])->toBe('www1');
});

it('keeps the handle inside the 63-char DNS label limit, suffix included', function () {
    $handle = app(HandleAllocator::class)->allocate(str_repeat('a', 90))['handle'];
    expect(strlen($handle))->toBe(63);

    User::factory()->create(['handle' => $handle, 'handle_lc' => $handle]);
    $next = app(HandleAllocator::class)->allocate(str_repeat('a', 90))['handle'];
    expect(strlen($next))->toBe(63)->and($next)->toEndWith('1');
});
