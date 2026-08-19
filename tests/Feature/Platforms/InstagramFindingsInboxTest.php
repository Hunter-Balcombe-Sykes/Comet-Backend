<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

// What a bio scan found, and what the owner does about it.
//
// Was InstagramSyncedTest, against GET/POST /platforms/instagram/synced. The
// owner retired that modal on 2026-08-19 — the same question ("we found this,
// what do you want to do?") was being asked in two places — so every scenario
// here moved to the one place it is asked now: GET /routing/suggestions, with
// legacy payload findings folded in as rows addressed
// `sync:{holder}:{platform}` and accepted through the inbox's own accept.
//
// The shape of the answer changed with it. A CONFLICT is a row: it still needs
// a decision. A SEEDED finding is not: it says "we connected this for you",
// which the Platforms page already shows — an inbox that re-lists settled work
// is one people stop reading. The tests that pinned the modal's rendering of
// seeded findings (status synced/syncing, removePath) now pin that they stay
// OUT of the inbox and that the connection itself is right, which is the part
// that was ever load-bearing.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
});

function igSyncedUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

/** Only the rows folded from a payload ledger — the intent-backed ones are SuggestionsInboxTest's. */
function foldedRows(User $user): Collection
{
    return collect(actingAsUser($user)->getJson('/api/routing/suggestions')->assertOk()->json('suggestions'))
        ->filter(fn (array $row) => str_starts_with((string) $row['id'], 'sync:'))
        ->values();
}

// ── what the inbox lists ──────────────────────────────────────────────────────

it('has nothing to fold when the user has no Instagram connection at all', function () {
    expect(foldedRows(igSyncedUser('igsy1'))->all())->toBe([]);
});

it('has nothing to fold for an old connection that predates bio-sync (no syncFindings key at all)', function () {
    $user = igSyncedUser('igsy2');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        // Pre-BE2 shape — no website/bioLinks/syncFindings/unmatched keys.
        'payload' => ['username' => 'docpizza', 'mode' => 'automatic', 'images' => []],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    expect(foldedRows($user)->all())->toBe([]);
});

it('never asks about a finding it already seeded — that work is done and shows on Platforms', function () {
    $user = igSyncedUser('igsy3');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => [
            'username' => 'docpizza', 'mode' => 'automatic', 'images' => [],
            'syncFindings' => [[
                'platform' => 'facebook', 'resourceId' => 'facebook', 'category' => 'social',
                'label' => 'Facebook', 'foundUrl' => 'https://www.facebook.com/docpizzabar',
                'outcome' => 'seeded', 'apply' => null,
            ]],
            'unmatched' => [['url' => 'https://linktr.ee/docpizza', 'label' => 'linktr.ee']],
        ],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $seeded = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'facebook', 'resource_id' => 'facebook',
        'payload' => ['username' => 'docpizzabar', 'url' => 'https://www.facebook.com/docpizzabar', 'source' => 'instagram'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    expect(foldedRows($user)->all())->toBe([])
        ->and($seeded->fresh()->payload['url'])->toBe('https://www.facebook.com/docpizzabar');
});

it('does not ask about a seeded finding whose connection is still syncing', function () {
    // The modal drew a 'syncing' pill off last_refresh_status. Nothing to ask
    // either way — the row exists and the fetch is the fetch's business.
    $user = igSyncedUser('igsy4');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['syncFindings' => [[
            'platform' => 'fresha', 'resourceId' => 'fresha', 'category' => 'booking',
            'label' => 'Fresha', 'foundUrl' => 'https://www.fresha.com/a/doc-cuts',
            'outcome' => 'seeded', 'apply' => null,
        ]], 'unmatched' => []],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/doc-cuts', 'source' => 'instagram'],
        'is_active' => false, 'last_refresh_status' => 'pending',
    ]);

    expect(foldedRows($user)->all())->toBe([]);
});

it('does not resurrect a seeded finding whose connection the user removed', function () {
    // The modal dropped these so a removed row could not linger in the list.
    // The inbox never listed them, so removal cannot bring one back either.
    $user = igSyncedUser('igsy5');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['syncFindings' => [[
            'platform' => 'tiktok', 'resourceId' => 'tiktok', 'category' => 'social',
            'label' => 'TikTok', 'foundUrl' => 'https://www.tiktok.com/@docpizza',
            'outcome' => 'seeded', 'apply' => null,
        ]], 'unmatched' => []],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    // No tiktok row exists — user removed it after it seeded.

    expect(foldedRows($user)->all())->toBe([]);
});

it('asks about a conflict, as an ordinary Swap row', function () {
    $user = igSyncedUser('igsy6');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['syncFindings' => [[
            'platform' => 'facebook', 'resourceId' => 'facebook', 'category' => 'social',
            'label' => 'Facebook', 'foundUrl' => 'https://www.facebook.com/docpizzabar',
            'outcome' => 'conflict',
            'apply' => ['remove' => ['facebook'], 'write' => [
                'platform' => 'facebook', 'resourceId' => 'facebook',
                'payload' => ['username' => 'docpizzabar', 'url' => 'https://www.facebook.com/docpizzabar', 'source' => 'instagram'],
            ]],
        ]], 'unmatched' => []],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'facebook', 'resource_id' => 'facebook',
        'payload' => ['username' => 'mine', 'url' => 'https://facebook.com/mine', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $row = foldedRows($user)->first();
    expect($row['id'])->toBe('sync:instagram:facebook')
        ->and($row['displayName'])->toBe('Facebook')
        ->and($row['url'])->toBe('https://www.facebook.com/docpizzabar')
        ->and($row['blockReason'])->toBe('conflict')
        ->and($row['actions'])->toBe(['replace', 'dismiss'])
        ->and($row['origin'])->toBe('bio_harvest');
});

// ── answering one ─────────────────────────────────────────────────────────────

it('swaps the conflict in on accept, and settles the finding so it is not re-asked', function () {
    $user = igSyncedUser('igsy7');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['syncFindings' => [[
            'platform' => 'facebook', 'resourceId' => 'facebook', 'category' => 'social',
            'label' => 'Facebook', 'foundUrl' => 'https://www.facebook.com/docpizzabar',
            'outcome' => 'conflict',
            'apply' => ['remove' => ['facebook'], 'write' => [
                'platform' => 'facebook', 'resourceId' => 'facebook',
                'payload' => ['username' => 'docpizzabar', 'url' => 'https://www.facebook.com/docpizzabar', 'source' => 'instagram'],
            ]],
        ]], 'unmatched' => []],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'facebook', 'resource_id' => 'facebook',
        'payload' => ['username' => 'mine', 'url' => 'https://facebook.com/mine', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->postJson('/api/routing/suggestions/sync:instagram:facebook/accept')
        ->assertOk()
        ->assertJsonPath('displayName', 'Facebook');

    $fb = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->get();
    expect($fb)->toHaveCount(1)
        ->and($fb->first()->payload['source'])->toBe('instagram')
        ->and($fb->first()->payload['url'])->toBe('https://www.facebook.com/docpizzabar');

    $ig = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'instagram')->firstOrFail();
    expect($ig->payload['syncFindings'][0]['outcome'])->toBe('seeded');

    // And it is gone from the inbox — an answered question is not re-asked.
    expect(foldedRows($user)->all())->toBe([]);
});

it('drops the finding on Not now, without connecting anything', function () {
    $user = igSyncedUser('igsy7b');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['syncFindings' => [[
            'platform' => 'facebook', 'resourceId' => 'facebook', 'category' => 'social',
            'label' => 'Facebook', 'foundUrl' => 'https://www.facebook.com/docpizzabar',
            'outcome' => 'conflict',
            'apply' => ['remove' => ['facebook'], 'write' => [
                'platform' => 'facebook', 'resourceId' => 'facebook',
                'payload' => ['username' => 'docpizzabar', 'url' => 'https://www.facebook.com/docpizzabar', 'source' => 'instagram'],
            ]],
        ]], 'unmatched' => []],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->postJson('/api/routing/suggestions/sync:instagram:facebook/dismiss')->assertOk();

    expect(foldedRows($user)->all())->toBe([])
        ->and(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->exists())->toBeFalse();
});

it('404s when there is nothing to change for that platform', function () {
    $user = igSyncedUser('igsy8');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['syncFindings' => [], 'unmatched' => []],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->postJson('/api/routing/suggestions/sync:instagram:facebook/accept')
        ->assertStatus(404);
});

it('404s when the user has no Instagram connection at all', function () {
    actingAsUser(igSyncedUser('igsy9'))
        ->postJson('/api/routing/suggestions/sync:instagram:facebook/accept')
        ->assertStatus(404);
});

it('404s an id naming a holder that carries no findings ledger', function () {
    // The old endpoint validated `platform` against the registry. The id is
    // the address of a finding now, so an id addressing nothing simply finds
    // nothing — same answer, one fewer thing to keep in sync.
    $user = igSyncedUser('igsy10');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['syncFindings' => [], 'unmatched' => []],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->postJson('/api/routing/suggestions/sync:tiktok:facebook/accept')->assertStatus(404);
    actingAsUser($user)->postJson('/api/routing/suggestions/sync:instagram:not-a-real-platform/accept')->assertStatus(404);
});

it('preserves an unrelated finding when applying a swap for a different platform', function () {
    $user = igSyncedUser('igsy11');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['syncFindings' => [
            [
                'platform' => 'facebook', 'resourceId' => 'facebook', 'category' => 'social',
                'label' => 'Facebook', 'foundUrl' => 'https://www.facebook.com/docpizzabar',
                'outcome' => 'conflict',
                'apply' => ['remove' => ['facebook'], 'write' => [
                    'platform' => 'facebook', 'resourceId' => 'facebook',
                    'payload' => ['username' => 'docpizzabar', 'url' => 'https://www.facebook.com/docpizzabar', 'source' => 'instagram'],
                ]],
            ],
            [
                'platform' => 'tiktok', 'resourceId' => 'tiktok', 'category' => 'social',
                'label' => 'TikTok', 'foundUrl' => 'https://www.tiktok.com/@docpizza',
                'outcome' => 'seeded', 'apply' => null,
            ],
        ], 'unmatched' => []],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'facebook', 'resource_id' => 'facebook',
        'payload' => ['username' => 'mine', 'url' => 'https://facebook.com/mine', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'tiktok', 'resource_id' => 'tiktok',
        'payload' => ['username' => 'docpizza', 'url' => 'https://www.tiktok.com/@docpizza', 'source' => 'instagram'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->postJson('/api/routing/suggestions/sync:instagram:facebook/accept')->assertOk();

    $findings = IntegrationConnection::query()->where('user_id', $user->id)
        ->where('platform', 'instagram')->firstOrFail()->payload['syncFindings'];
    expect($findings)->toHaveCount(2)
        ->and(collect($findings)->firstWhere('platform', 'facebook')['outcome'])->toBe('seeded')
        ->and(collect($findings)->firstWhere('platform', 'tiktok')['outcome'])->toBe('seeded');
});
