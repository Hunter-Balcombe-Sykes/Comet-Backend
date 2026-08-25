<?php

use App\Models\Core\User\User;
use App\Routing\ConnectionIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// SEM-14 — matchExisting() has three lookup schemes. Scheme 1 always gated its
// case-folding on CASE_INSENSITIVE_HANDLE_SURFACES, because IdentifierKind::Handle
// is a PARSE-strategy label that also covers case-SENSITIVE opaque codes — the
// class docblock names discord.server invite codes as the exact reason. Schemes 2
// (the FOUND-14 canonical_key column) and 3 folded unconditionally, so as soon as
// a non-allowlisted row carried a canonical_key, two genuinely distinct
// case-differing identifiers matched as the same connection.
//
// Within one tenant — the query is user-scoped — so this is a correctness bug,
// not a tenancy one.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function discordConnection(object $pro, string $resourceId, ?string $canonicalKey): string
{
    $id = (string) Str::uuid();
    DB::table('site.platform_connections')->insert([
        'id' => $id,
        'user_id' => $pro->id,
        'surface_key' => 'discord.server',
        'resource_id' => $resourceId,
        'canonical_key' => $canonicalKey,
        'routing_class' => 'social',
        'payload' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('does NOT fold case on a non-allowlisted surface when the row carries a canonical_key (SEM-14)', function () {
    $pro = createTenant('sem14-discord');
    // Two Discord invite codes differing ONLY in case are two different invites.
    discordConnection($pro, 'aBcDeF', 'aBcDeF');

    $match = app(ConnectionIdentity::class)->matchExisting(
        User::find($pro->id), 'discord.server', 'ABCDEF'
    );

    expect($match)->toBeNull();
});

it('still matches the SAME case on a non-allowlisted surface via canonical_key (SEM-14)', function () {
    // The other direction: gating the fold must not break the lookup itself.
    $pro = createTenant('sem14-discord-same');
    $id = discordConnection($pro, 'zzz-marker', 'aBcDeF');

    $match = app(ConnectionIdentity::class)->matchExisting(
        User::find($pro->id), 'discord.server', 'aBcDeF'
    );

    expect($match)->toBe($id);
});

it('still folds case on an ALLOWLISTED surface (SEM-14 must not over-correct)', function () {
    $pro = createTenant('sem14-youtube');
    $id = (string) Str::uuid();
    DB::table('site.platform_connections')->insert([
        'id' => $id, 'user_id' => $pro->id, 'surface_key' => 'youtube.channel',
        'resource_id' => 'zzz-marker', 'canonical_key' => 'TheJungleGiants', 'routing_class' => 'social',
        'payload' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $match = app(ConnectionIdentity::class)->matchExisting(
        User::find($pro->id), 'youtube.channel', 'thejunglegiants'
    );

    expect($match)->toBe($id);
});
