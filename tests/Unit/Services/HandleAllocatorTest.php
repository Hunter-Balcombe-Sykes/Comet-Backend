<?php

use App\Models\Core\User\User;
use App\Services\User\HandleAllocator;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(fn () => setupUsersTable());

it('slugs the seed and suffixes bare integers on collision (bootstrap-identical)', function () {
    $alloc = app(HandleAllocator::class);

    expect($alloc->allocate('Jane Doe'))->toBe(['handle' => 'jane-doe', 'handle_lc' => 'jane-doe']);

    User::factory()->create(['handle' => 'jane-doe', 'handle_lc' => 'jane-doe']);
    expect($alloc->allocate('Jane Doe'))->toBe(['handle' => 'jane-doe1', 'handle_lc' => 'jane-doe1']);
});

it('falls back to "professional" for an empty slug', function () {
    expect(app(HandleAllocator::class)->allocate('$$$')['handle'])->toBe('professional');
});
