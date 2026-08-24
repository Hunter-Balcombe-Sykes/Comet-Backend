<?php

// The characterization the P8 readiness doc calls for (blocker B5): a
// connection the user deleted, then re-SCANNED, stays dead.
//
// Under the legacy pipeline a user's refusal WAS the soft-deleted row, and
// each legacy seeder checked for it before writing (e.g.
// ShopBrandSeeder.php:51-59). SourceReconciler does not — it looks only for a
// LIVE row (whereNull('deleted_at')), so a soft-deleted connection is
// invisible to it. That is the whole reason
// 20260728120000_backfill_item_tombstones.sql exists, and the reason the
// readiness doc orders it before any scan path migrates. The re-scan hazard
// test below deliberately proves the bug the backfill closes: delete the
// backfill and it starts describing production.
//
// Origin decides the rest (owner decision, 2026-07-28): a tombstone only
// suppresses INDIRECT origins (scan / harvest / suggestion). An explicit
// paste is the user directly asking for the link back, so it wins — the
// connection is re-created and the superseded surface:identifier tombstone is
// deleted, keeping later scans of the restored link honest.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Routing\Importers\LinkInBioImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    // The importer reads the custom_links pool on its SUCCESS path too now
    // (2026-08-24: retiring a floor card the same page no longer needs), so
    // the content lane must exist even here, where only routing rows are
    // asserted — the same reason LinkInBioImporterTest provisions it.
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

/** The connection a user connected and then deleted, as the legacy pipeline left it. */
function deletedConnection(User $user, string $surfaceKey, string $resourceId): IntegrationConnection
{
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'surface_key' => $surfaceKey,
        'resource_id' => $resourceId,
        'payload' => ['url' => 'https://www.instagram.com/'.$resourceId],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
    $connection->delete();

    return $connection;
}

/** Exactly what supabase/migrations/20260728120000 writes for that row. */
function backfilledTombstone(User $user, string $surfaceKey, string $resourceId): void
{
    DB::table('routing.item_tombstones')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'source_ref' => $surfaceKey.':'.$resourceId,
        'scope' => 'this_source',
        'reason' => 'legacy soft-deleted connection, backfilled 2026-07-28',
        'created_at' => now()->subDays(7),
    ]);
}

/** A harvest path — the migration target for LinkInBioScanJob / InstagramAutoSync. */
function rescanBio(User $user, string $link): array
{
    Http::fake(['*' => Http::response('<html><body><a href="'.$link.'">link</a></body></html>', 200)]);

    return app(LinkInBioImporter::class)->import($user, 'https://example.com/theartist');
}

// ── The hazard ───────────────────────────────────────────────────────────────

it('re-offers a deleted connection on re-scan when nothing records the refusal', function () {
    $pro = createTenant('tombstone-hazard-scan');
    deletedConnection($pro, 'instagram.profile', 'theartist');

    rescanBio($pro, 'https://www.instagram.com/theartist');

    expect(DB::table('routing.source_intents')->where('user_id', $pro->id)->count())->toBe(1);
});

// ── The fix ──────────────────────────────────────────────────────────────────

it('keeps a deleted connection dead on re-scan once the refusal is backfilled', function () {
    $pro = createTenant('tombstone-scan');
    deletedConnection($pro, 'instagram.profile', 'theartist');
    backfilledTombstone($pro, 'instagram.profile', 'theartist');

    $result = rescanBio($pro, 'https://www.instagram.com/theartist');

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0)
        // The link is still SEEN — refusing to connect it is not the same as
        // pretending the scan never found it.
        ->and($result['observations'])->toBe(1);
});

it('writes no intent for a refused link, so the inbox never re-offers it', function () {
    // A Hold would put the platform straight back in front of the user as a
    // suggestion — a slower resurrection, not a prevented one.
    $pro = createTenant('tombstone-no-intent');
    deletedConnection($pro, 'instagram.profile', 'theartist');
    backfilledTombstone($pro, 'instagram.profile', 'theartist');

    rescanBio($pro, 'https://www.instagram.com/theartist');

    expect(DB::table('routing.source_intents')->where('user_id', $pro->id)->count())->toBe(0)
        ->and(DB::table('routing.link_observations')->where('user_id', $pro->id)->value('block_reason'))
        ->toBe('tombstoned');
});

it('leaves the user\'s deletion deleted rather than reviving the old row', function () {
    $pro = createTenant('tombstone-stays-deleted');
    $connection = deletedConnection($pro, 'instagram.profile', 'theartist');
    backfilledTombstone($pro, 'instagram.profile', 'theartist');

    rescanBio($pro, 'https://www.instagram.com/theartist');

    expect(IntegrationConnection::withTrashed()->find($connection->id)->deleted_at)->not->toBeNull();
});

// ── Direct requests win ──────────────────────────────────────────────────────

it('lets an explicit re-paste beat the tombstone and clears the refusal', function () {
    // The user pasting the URL again IS the counter-instruction to their old
    // deletion — suppressing it would leave them no way back at all.
    $pro = createTenant('tombstone-direct-paste');
    deletedConnection($pro, 'instagram.profile', 'theartist');
    backfilledTombstone($pro, 'instagram.profile', 'theartist');

    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://www.instagram.com/theartist'])
        ->assertStatus(202)
        ->assertJsonPath('verdict', 'place')
        ->assertJsonPath('outcome', 'connected');

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(1)
        // The refusal is superseded, not left to fight the restored link.
        ->and(DB::table('routing.item_tombstones')
            ->where('user_id', $pro->id)
            ->where('source_ref', 'instagram.profile:theartist')
            ->exists())->toBeFalse();
});

it('stops suppressing scans of a link the user explicitly restored', function () {
    $pro = createTenant('tombstone-restored-scan');
    deletedConnection($pro, 'instagram.profile', 'theartist');
    backfilledTombstone($pro, 'instagram.profile', 'theartist');

    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://www.instagram.com/theartist'])
        ->assertStatus(202);

    // With the tombstone superseded, a later harvest folds onto the live
    // connection instead of rejecting the URL the user just asked for.
    rescanBio($pro, 'https://www.instagram.com/theartist');

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(1)
        ->and(DB::table('routing.link_observations')->where('user_id', $pro->id)->where('block_reason', 'tombstoned')->count())
        ->toBe(0);
});

it('clears only the exact refusal, never a whole-surface one', function () {
    // A bare surface ref refuses EVERY account on the surface. Re-adding one
    // account answers nothing about the others, so that wider refusal stays.
    $pro = createTenant('tombstone-bare-survives');
    DB::table('routing.item_tombstones')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'source_ref' => 'instagram.profile',
        'scope' => 'this_source',
        'reason' => 'whole-surface refusal',
        'created_at' => now()->subDays(7),
    ]);

    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://www.instagram.com/theartist'])
        ->assertStatus(202)
        ->assertJsonPath('verdict', 'place');

    expect(DB::table('routing.item_tombstones')
        ->where('user_id', $pro->id)
        ->where('source_ref', 'instagram.profile')
        ->exists())->toBeTrue();
});

// ── The blast radius ─────────────────────────────────────────────────────────

it('refuses only the account the user deleted, not the whole platform', function () {
    // scope='this_source' with a surface:identifier ref. The reader also
    // honours a BARE surface ref — writing one would have blocked every other
    // Instagram account the user has.
    $pro = createTenant('tombstone-narrow');
    deletedConnection($pro, 'instagram.profile', 'oldhandle');
    backfilledTombstone($pro, 'instagram.profile', 'oldhandle');

    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://www.instagram.com/newhandle'])
        ->assertStatus(202)
        ->assertJsonPath('verdict', 'place');

    $live = IntegrationConnection::query()->where('user_id', $pro->id)->get();
    expect($live)->toHaveCount(1)
        ->and($live[0]->resource_id)->toBe('newhandle');
});

it('does not let one user\'s refusal block another user', function () {
    $refuser = createTenant('tombstone-refuser');
    $other = createTenant('tombstone-other');
    deletedConnection($refuser, 'instagram.profile', 'theartist');
    backfilledTombstone($refuser, 'instagram.profile', 'theartist');

    actingAsUser($other)->postJson('/api/routing/links', ['url' => 'https://www.instagram.com/theartist'])
        ->assertStatus(202)
        ->assertJsonPath('verdict', 'place');

    expect(IntegrationConnection::query()->where('user_id', $other->id)->count())->toBe(1)
        ->and(IntegrationConnection::query()->where('user_id', $refuser->id)->count())->toBe(0);
});
