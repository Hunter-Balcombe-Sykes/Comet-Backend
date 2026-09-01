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

it('slugs the display name with NO separator and suffixes bare integers on collision (Item 1c)', function () {
    $alloc = app(HandleAllocator::class);

    expect($alloc->allocate('Jane Doe'))->toBe(['handle' => 'janedoe', 'handle_lc' => 'janedoe']);

    User::factory()->create(['handle' => 'janedoe', 'handle_lc' => 'janedoe']);
    expect($alloc->allocate('Jane Doe'))->toBe(['handle' => 'janedoe1', 'handle_lc' => 'janedoe1']);
});

it('prefers the untrimmed name over a digit when the cleaned name collides (Item 1c ladder)', function () {
    $alloc = app(HandleAllocator::class);
    User::factory()->create(['handle' => 'thefamishedwolf', 'handle_lc' => 'thefamishedwolf']);

    // The brand's second location gets its real distinguisher — the suburb —
    // never a number, as long as that name is free.
    expect($alloc->allocate('The Famished Wolf', 'The Famished Wolf Kensington')['handle'])
        ->toBe('thefamishedwolfkensington');

    User::factory()->create(['handle' => 'thefamishedwolfkensington', 'handle_lc' => 'thefamishedwolfkensington']);
    expect($alloc->allocate('The Famished Wolf', 'The Famished Wolf Kensington')['handle'])
        ->toBe('thefamishedwolf1');
});

it('deletes dots rather than hyphenating them (by.dannydixon shape)', function () {
    expect(app(HandleAllocator::class)->allocate('by.dannydixon')['handle'])->toBe('bydannydixon');
});

it('falls back to "professional" for an empty slug', function () {
    expect(app(HandleAllocator::class)->allocate('$$$')['handle'])->toBe('professional');
});

it('treats a subdomain held by another site as unavailable', function () {
    $other = User::factory()->create(['handle' => 'other', 'handle_lc' => 'other']);
    Site::factory()->create(['user_id' => $other->id, 'subdomain' => 'janedoe']);

    expect(app(HandleAllocator::class)->allocate('Jane Doe')['handle'])->toBe('janedoe1');
});

it('treats a subdomain held by an active alias as unavailable, but not an expired one', function () {
    $other = User::factory()->create(['handle' => 'other', 'handle_lc' => 'other']);
    $site = Site::factory()->create(['user_id' => $other->id, 'subdomain' => 'other']);

    $alias = ['id' => (string) Str::uuid(), 'site_id' => $site->id, 'subdomain' => 'janedoe', 'created_at' => now()];

    DB::connection('pgsql')->table('site.site_subdomain_aliases')
        ->insert($alias + ['expires_at' => now()->addDays(90)]);
    expect(app(HandleAllocator::class)->allocate('Jane Doe')['handle'])->toBe('janedoe1');

    DB::connection('pgsql')->table('site.site_subdomain_aliases')
        ->where('id', $alias['id'])->update(['expires_at' => now()->subDay()]);
    expect(app(HandleAllocator::class)->allocate('Jane Doe')['handle'])->toBe('janedoe');
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
