<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramAutoSync;

// An Instagram link found in an Instagram BIO is never an Instagram suggestion
// (owner, 2026-09-03).
//
// We only ever read a bio because that account is already connected — that is
// how the scrape happened. So any OTHER instagram.com URL in it belongs to
// someone else: the salon the person works at, a friend, a brand they tagged.
// Routed like any other link it became an instagram.profile candidate, and
// against the user's existing primary that renders as a "Change to" swap —
// the product offering to replace someone's real account with their
// workplace's.
//
// The handles are still worth having; they are a MEANS, not an end. The owner's
// rule is to follow them for online stores and any resolvable Google Business
// and suggest THOSE. That chaining lives in the bio-mention lane and is not
// wired from here yet, so this drops them and logs, rather than pretending to
// chain. The non-Instagram assertion below is the one that fails if someone
// later widens the guard into "ignore bio links".

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    setupContentTables();
    setupIngestTables();
});

function bioSyncUser(): User
{
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'biosync-'.substr($user->id, 0, 8), 'is_published' => false]);

    return $user;
}

it('never turns an Instagram link in the bio into an Instagram connection', function () {
    $user = bioSyncUser();

    app(InstagramAutoSync::class)->seed((string) $user->id, [
        'https://www.instagram.com/youthofdulwich',
    ]);

    expect(IntegrationConnection::query()
        ->where('user_id', $user->id)
        ->where('platform', 'instagram')
        ->exists())->toBeFalse();
});

it('does not offer that Instagram as a plain link either', function () {
    $user = bioSyncUser();

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, [
        'https://www.instagram.com/youthofdulwich',
    ]);

    $urls = array_column($result['unmatched'], 'url');

    expect($result['findings'])->toBe([])
        ->and($urls)->not->toContain('https://www.instagram.com/youthofdulwich');
});

it('still routes every other platform found in the bio', function () {
    // The guard is Instagram-specific. A YouTube channel in the same bio is
    // exactly the kind of link this lane exists to find.
    $user = bioSyncUser();

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, [
        'https://www.instagram.com/youthofdulwich',
        'https://www.youtube.com/@examplechannel',
    ]);

    expect(IntegrationConnection::query()
        ->where('user_id', $user->id)
        ->where('platform', 'youtube')
        ->exists())->toBeTrue();
});
