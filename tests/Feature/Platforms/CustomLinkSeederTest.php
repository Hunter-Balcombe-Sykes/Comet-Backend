<?php

use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\CustomLinkSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('seeds a custom link card idempotently, enriches it, and enforces the same 20-link cap the manual UI uses', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);

    $row = app(CustomLinkSeeder::class)->seed($user, 'https://someblog.example/post');
    app(CustomLinkSeeder::class)->seed($user, 'https://someblog.example/post'); // again

    $rows = IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'custom'])->get();
    expect($rows)->toHaveCount(1);
    expect($row->resource_kind)->toBe('link');
    Queue::assertPushed(EnrichLinkCardJob::class, 1); // enrichment dispatched once, not on the idempotent re-seed
});

it('refuses to seed a link for a pending-deletion account', function () {
    $user = User::factory()->create(['status' => 'pending_deletion']);
    expect(app(CustomLinkSeeder::class)->seed($user, 'https://example.com'))->toBeNull();
    expect(IntegrationConnection::where('user_id', $user->id)->exists())->toBeFalse();
});

it('stops at the 20-link cap', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    for ($i = 0; $i < 20; $i++) {
        $result = app(CustomLinkSeeder::class)->seed($user, "https://example{$i}.com");
        expect($result)->not->toBeNull();
    }
    $result = app(CustomLinkSeeder::class)->seed($user, 'https://one-too-many.example');
    expect($result)->toBeNull();
    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'custom'])->count())->toBe(20);
});

it('returns null for a URL that fails to normalize', function () {
    $user = User::factory()->create();
    expect(app(CustomLinkSeeder::class)->seed($user, 'not a url'))->toBeNull();
});

it('respects an existing link already at the cap — re-seeding it is still idempotent, not blocked', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);
    for ($i = 0; $i < 20; $i++) {
        app(CustomLinkSeeder::class)->seed($user, "https://example{$i}.com");
    }
    // Re-seeding the FIRST link (already stored) must still succeed — the cap
    // only blocks genuinely NEW rows, not idempotent re-seeds of an existing one.
    $result = app(CustomLinkSeeder::class)->seed($user, 'https://example0.com');
    expect($result)->not->toBeNull();
});
