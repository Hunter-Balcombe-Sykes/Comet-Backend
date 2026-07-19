<?php

use App\Jobs\Platforms\LinkInBioScanJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('unrolls a link-in-bio page into a seeded integration and a custom link, without persisting the bio-link url itself', function () {
    Queue::fake(); // do not let CustomLinkSeeder's EnrichLinkCardJob actually run
    $user = User::factory()->create(['account_type' => 'business']);
    Http::fake([
        'linktr.ee/*' => Http::response(
            '<a href="https://www.fresha.com/a/venue-1">Book</a><a href="https://someblog.example">Blog</a>',
            200
        ),
    ]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(
        app(App\Services\Http\SafeUrlFetcher::class),
        app(App\Services\Platforms\WebsiteLinkHarvester::class),
        app(App\Services\Platforms\InstagramAutoSync::class),
        app(App\Services\Platforms\CustomLinkSeeder::class),
    );

    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'fresha'])->exists())->toBeTrue();
    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'custom'])->exists())->toBeTrue();
    expect(IntegrationConnection::where('payload->url', 'https://linktr.ee/venue')->exists())->toBeFalse();
});

it('falls back to CustomLinkSeeder for a classified-but-gated link instead of dropping it', function () {
    Queue::fake();
    // A food-sector BUSINESS account: can_use_booking is sector-gated only for
    // Business accounts (AccountCapabilities::individualCapabilities() — a
    // partna account's can_use_booking is always true, food sector or not).
    // fresha is gated here, so handleClassifiedLink() routes it to $unmatched
    // — this job must still turn that into a custom link, not silently drop it.
    $user = User::factory()->create(['account_type' => 'business', 'sector' => 'restaurant']);
    Http::fake([
        'linktr.ee/*' => Http::response('<a href="https://www.fresha.com/a/venue-1">Book</a>', 200),
    ]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(
        app(App\Services\Http\SafeUrlFetcher::class),
        app(App\Services\Platforms\WebsiteLinkHarvester::class),
        app(App\Services\Platforms\InstagramAutoSync::class),
        app(App\Services\Platforms\CustomLinkSeeder::class),
    );

    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'fresha'])->exists())->toBeFalse();
    $custom = IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'custom'])->first();
    expect($custom)->not->toBeNull();
    expect($custom->payload['url'])->toBe('https://www.fresha.com/a/venue-1');
});

it('does nothing when the fetch fails', function () {
    $user = User::factory()->create();
    Http::fake(['linktr.ee/*' => Http::response('', 404)]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(
        app(App\Services\Http\SafeUrlFetcher::class),
        app(App\Services\Platforms\WebsiteLinkHarvester::class),
        app(App\Services\Platforms\InstagramAutoSync::class),
        app(App\Services\Platforms\CustomLinkSeeder::class),
    );

    expect(IntegrationConnection::where('user_id', $user->id)->exists())->toBeFalse();
});

it('does nothing when the user no longer exists', function () {
    // Must not throw — mirrors ScanPreviousWebsiteContentJob's own null-user guard.
    (new LinkInBioScanJob((string) Str::uuid(), 'https://linktr.ee/venue'))->handle(
        app(App\Services\Http\SafeUrlFetcher::class),
        app(App\Services\Platforms\WebsiteLinkHarvester::class),
        app(App\Services\Platforms\InstagramAutoSync::class),
        app(App\Services\Platforms\CustomLinkSeeder::class),
    );

    expect(true)->toBeTrue(); // reaching here without throwing is the assertion
});
