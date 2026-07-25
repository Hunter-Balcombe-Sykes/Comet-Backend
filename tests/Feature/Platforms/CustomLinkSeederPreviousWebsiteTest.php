<?php

// L1: CustomLinkSeeder is the single chokepoint for every auto-scrape link
// path (InstagramConnectionSeeder's bio-link auto-save, LinkInBioScanJob,
// WebsiteLinkHarvester) — a scrape must never re-add the user's own previous
// website (the very site the Partna page replaces) as a custom link. Manual
// link-adds go through CustomLinksController::addLink(), which doesn't touch
// this class, so a user can still add their old site by hand if they want.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\CustomLinkSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupWorkplacesTable();
});

function seedPreviousWebsite(User $user, string $url): void
{
    DB::connection('pgsql')->table('site.workplaces')->updateOrInsert(
        ['site_id' => $user->site->id],
        ['previous_website' => $url],
    );
}

it("skips an auto-grabbed link that is the user's previous website (exact match)", function () {
    $user = createTenant('cls-exact');
    seedPreviousWebsite($user, 'https://thebrokenovenpizzabar.com.au/');

    $result = app(CustomLinkSeeder::class)->seedCustom($user->fresh(), 'https://thebrokenovenpizzabar.com.au/');

    expect($result)->toBeNull();
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'custom')->count())->toBe(0);
});

it("skips a subpage of the user's previous website", function () {
    $user = createTenant('cls-subpage');
    seedPreviousWebsite($user, 'https://thebrokenovenpizzabar.com.au/');

    $result = app(CustomLinkSeeder::class)->seedCustom($user->fresh(), 'https://thebrokenovenpizzabar.com.au/menu');

    expect($result)->toBeNull();
});

it('skips a www. variant of the previous website host', function () {
    $user = createTenant('cls-www');
    seedPreviousWebsite($user, 'https://thebrokenovenpizzabar.com.au/');

    $result = app(CustomLinkSeeder::class)->seedCustom($user->fresh(), 'https://www.thebrokenovenpizzabar.com.au/contact');

    expect($result)->toBeNull();
});

it('still seeds an auto-grabbed link to a genuinely different host', function () {
    $user = createTenant('cls-different');
    seedPreviousWebsite($user, 'https://thebrokenovenpizzabar.com.au/');

    $result = app(CustomLinkSeeder::class)->seedCustom($user->fresh(), 'https://www.instagram.com/brokenovenpizzabar');

    expect($result)->not->toBeNull();
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'custom')->count())->toBe(1);
});

it('seeds normally when the user has no previous_website set at all', function () {
    $user = createTenant('cls-none');
    // No workplace row at all.

    $result = app(CustomLinkSeeder::class)->seedCustom($user->fresh(), 'https://www.instagram.com/somebusiness');

    expect($result)->not->toBeNull();
});

it("does not confuse a host that merely CONTAINS the previous website's host as a substring", function () {
    $user = createTenant('cls-substring');
    seedPreviousWebsite($user, 'https://oven.com.au/');

    // notoven.com.au contains "oven.com.au" as a raw substring but is a
    // genuinely different host — a naive str_contains() match would wrongly
    // skip this.
    $result = app(CustomLinkSeeder::class)->seedCustom($user->fresh(), 'https://notoven.com.au/');

    expect($result)->not->toBeNull();
});
