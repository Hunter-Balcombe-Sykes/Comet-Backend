<?php

// Slice 7 Task 11 (spec D3) — the CHARACTERISATION ORACLE for moving
// `payload.selection` off `site.services`.
//
// D3 keeps the blob on the public wire and changes only where it is composed
// FROM. That makes byte-equality the acceptance criterion, and byte-equality
// needs an oracle: this file snapshots `FreshaServiceProjector::compose()`'s
// EXACT output for each of the four behaviours the cutover must carry across,
// so a future content.*-backed compose() can be diffed against a recorded
// fact rather than against someone's memory of one.
//
// Every expectation below describes TODAY (the `site.services` projection).
// The cutover flips exactly one of them — `composes NOTHING once the legacy
// rows are truncated` — and must leave the other five byte-identical. If a
// cutover changes any other expectation here, it is a public wire change on a
// booking surface and needs its own manifest entry, not a test edit.
//
// KNOWN BLOCKER, recorded here because this file is where it bites (full
// write-up in the task report): an owner's edited PRICE has no representation
// in `content.*`. `content.offers` is a set-union COLLECTION, and
// FacetRegistry excludes collections from `content.manual_overrides` by
// design ("no single value to override"). The `serializes the owner's edited
// version` case below therefore cannot be reproduced from content.* as the
// schema stands — `price` / `priceValue` would silently revert to the
// vendor's numbers on the public booking blob.

use App\Models\Core\User\Service;
use App\Models\Core\User\User;
use App\Services\Platforms\FreshaServiceProjector;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    shimPgAdvisoryLockForSqlite();
});

/**
 * A raw scrape entry in the exact shape FreshaScraper::extractServices emits.
 * File-local (prefix `fsfc`) — a cross-file helper of the same name is fatal
 * under --parallel.
 */
function fsfcRaw(string $id, string $name, ?string $category = null, mixed $priceValue = 50, ?string $duration = '45min'): array
{
    return [
        'serviceId' => $id,
        'name' => $name,
        'duration' => $duration,
        'description' => null,
        'price' => 'A$'.$priceValue,
        'priceValue' => $priceValue,
        'currency' => 'AUD',
        'category' => $category,
        'hasVariants' => false,
    ];
}

/** The one projected row for a serviceId, trashed rows included. */
function fsfcRow(User $user, string $serviceId): Service
{
    return Service::withTrashed()
        ->where('user_id', $user->id)
        ->where('source', 'fresha')
        ->where('external_id', $serviceId)
        ->firstOrFail();
}

it('composes the recorded oracle blob from a two-service scrape', function () {
    $user = createTenant('fsfc1');
    $raw = [fsfcRaw('s:1', 'Haircut', 'Hair', 65, '1h 15min'), fsfcRaw('s:2', 'Beard Trim', 'Hair', 27.5, '30min')];

    app(FreshaServiceProjector::class)->sync($user, $raw);

    // THE ORACLE. A synced row contributes its raw entry VERBATIM — same keys,
    // same key ORDER, same values, including Fresha's own 'A$65' display
    // string and the 'AUD' currency code the scrape carried. Pinned as encoded
    // JSON rather than a loose array so key order counts: the stored blob is
    // shipped to the CDN as JSON and a reordered object is a diff to anyone
    // byte-comparing it.
    expect(json_encode(app(FreshaServiceProjector::class)->compose($user, $raw)))->toBe(
        '{"services":['
        .'{"serviceId":"s:1","name":"Haircut","duration":"1h 15min","description":null,"price":"A$65","priceValue":65,"currency":"AUD","category":"Hair","hasVariants":false},'
        .'{"serviceId":"s:2","name":"Beard Trim","duration":"30min","description":null,"price":"A$27.5","priceValue":27.5,"currency":"AUD","category":"Hair","hasVariants":false}'
        .'],"hiddenServiceIds":[]}'
    );
});

it('composes NOTHING once the legacy rows are truncated', function () {
    $user = createTenant('fsfc2');
    $raw = [fsfcRaw('s:1', 'Haircut', 'Hair', 65)];
    app(FreshaServiceProjector::class)->sync($user, $raw);

    DB::connection('pgsql')->table('site.services')->delete();

    // This is the ONE expectation the cutover flips. compose() reads
    // site.services for existence, so with the table emptied every serviceId
    // reads as "suppressed or deleted" and drops out of the blob. After the
    // cutover this must return the oracle above instead, with content.* rows
    // standing in for the truncated ones.
    expect(app(FreshaServiceProjector::class)->compose($user, $raw))
        ->toBe(['services' => [], 'hiddenServiceIds' => []]);
});

it('serializes the owner-edited version and never lets a later sync overwrite it', function () {
    $user = createTenant('fsfc3');
    $projector = app(FreshaServiceProjector::class);
    $raw = [fsfcRaw('s:1', 'Haircut', 'Hair', 65), fsfcRaw('s:2', 'Trim', 'Hair', 20)];
    $projector->sync($user, $raw);

    // An owner edit detaches the row from the sync (is_manual). The API path
    // that sets this is covered next door in FreshaServiceProjectionTest; here
    // the state is set directly so the assertion is about compose()'s contract
    // and nothing else.
    fsfcRow($user, 's:1')->forceFill(['title' => 'Signature Haircut', 'price_cents' => 8000, 'is_manual' => true])->save();

    $composed = $projector->compose($user, $raw);

    // A DETACHED row contributes the SERIALIZED owner version — nine keys in
    // serialize()'s own order, the price re-rendered from the owner's cents
    // through formatPrice(), the currency read off the row's column. This is
    // the case content.* cannot reproduce today: `price` and `priceValue` are
    // owner-authored numbers with no overridable home (see the file header).
    expect($composed['services'][0])->toBe([
        'serviceId' => 's:1',
        'name' => 'Signature Haircut',
        'duration' => '45min',
        'description' => null,
        'price' => 'A$80',
        'priceValue' => 80.0,
        'currency' => 'AUD',
        'category' => 'Hair',
        'hasVariants' => false,
    ]);
    // ...while the untouched sibling still rides through verbatim.
    expect($composed['services'][1])->toBe($raw[1]);

    // A later scrape at a NEW price must not touch the detached row, and the
    // blob must keep serving the owner's numbers.
    $projector->sync($user, [fsfcRaw('s:1', 'Haircut', 'Hair', 70), fsfcRaw('s:2', 'Trim', 'Hair', 22)]);

    expect(fsfcRow($user, 's:1')->price_cents)->toBe(8000);
    expect(fsfcRow($user, 's:2')->price_cents)->toBe(2200);
    expect($projector->compose($user, $raw)['services'][0]['price'])->toBe('A$80');
});

it('suppresses an owner-deleted service and never re-creates it on a later sync', function () {
    $user = createTenant('fsfc4');
    $projector = app(FreshaServiceProjector::class);
    $raw = [fsfcRaw('s:1', 'Haircut', 'Hair', 65), fsfcRaw('s:2', 'Trim', 'Hair', 20)];
    $projector->sync($user, $raw);

    // The owner's delete — deleted_origin='user' is the suppression marker.
    $row = fsfcRow($user, 's:1');
    $row->deleted_origin = 'user';
    $row->saveQuietly();
    $row->delete();

    expect(array_column($projector->compose($user, $raw)['services'], 'serviceId'))->toBe(['s:2']);

    // The scrape still offers s:1. It must stay gone — from the row store AND
    // from the blob. (Post-cutover this is content.items.removed_at, which
    // ProjectionWriter never clears on reappearance. NEVER
    // source_items.removed_at: that IS cleared on reappearance and would
    // resurrect a service its owner deleted.)
    $projector->sync($user, $raw);

    expect(Service::query()->where('user_id', $user->id)->where('external_id', 's:1')->exists())->toBeFalse();
    expect(array_column($projector->compose($user, $raw)['services'], 'serviceId'))->toBe(['s:2']);
});

it('soft-deletes a departed service with sync origin and restores it when it returns', function () {
    $user = createTenant('fsfc5');
    $projector = app(FreshaServiceProjector::class);
    $both = [fsfcRaw('s:1', 'Haircut', 'Hair', 65), fsfcRaw('s:2', 'Trim', 'Hair', 20)];
    $projector->sync($user, $both);

    // s:2 vanishes from Fresha. Connector ABSENCE, not an owner act — hence
    // deleted_origin='sync' (post-cutover: content.source_items.removed_at).
    $projector->sync($user, [$both[0]]);

    expect(fsfcRow($user, 's:2')->trashed())->toBeTrue();
    expect(fsfcRow($user, 's:2')->deleted_origin)->toBe('sync');
    expect(array_column($projector->compose($user, $both)['services'], 'serviceId'))->toBe(['s:1']);

    // ...and comes back, at a new price. Absence-driven removal is REVERSIBLE;
    // that is the whole difference from the owner-delete case above.
    $projector->sync($user, [$both[0], fsfcRaw('s:2', 'Trim', 'Hair', 25)]);

    expect(fsfcRow($user, 's:2')->trashed())->toBeFalse();
    expect(fsfcRow($user, 's:2')->deleted_origin)->toBeNull();
    expect(fsfcRow($user, 's:2')->price_cents)->toBe(2500);
    expect(array_column($projector->compose($user, $both)['services'], 'serviceId'))->toBe(['s:1', 's:2']);
});

it('collapses a serviceId listed under several categories to its first occurrence', function () {
    $user = createTenant('fsfc6');
    $projector = app(FreshaServiceProjector::class);

    // Fresha lists a service once per category it appears in — the same
    // serviceId arrives several times in one scrape.
    $raw = [
        fsfcRaw('s:1', 'Haircut', 'Hair', 65),
        fsfcRaw('s:1', 'Haircut', 'Packages', 65),
        fsfcRaw('s:2', 'Trim', 'Hair', 20),
    ];
    $projector->sync($user, $raw);

    $composed = $projector->compose($user, $raw);

    // One entry per serviceId, and the FIRST occurrence's entry is the one
    // that survives — its 'Hair' category label wins over 'Packages'.
    expect(array_column($composed['services'], 'serviceId'))->toBe(['s:1', 's:2']);
    expect($composed['services'][0]['category'])->toBe('Hair');
    expect($composed['services'][0])->toBe($raw[0]);

    // The row store deduped too, while the duplicate LISTING still unions both
    // labels into the row's memberships.
    expect(Service::query()->where('user_id', $user->id)->where('source', 'fresha')->count())->toBe(2);
    expect(fsfcRow($user, 's:1')->categories->pluck('title')->sort()->values()->all())->toBe(['Hair', 'Packages']);
});

it('carries the hidden list on the blob, derived from the projections', function () {
    $user = createTenant('fsfc7');
    $projector = app(FreshaServiceProjector::class);
    $raw = [fsfcRaw('s:1', 'Haircut', 'Hair', 65), fsfcRaw('s:2', 'Trim', 'Hair', 20)];
    $projector->sync($user, $raw);

    fsfcRow($user, 's:2')->forceFill(['is_active' => false])->save();

    $composed = $projector->compose($user, $raw);

    // hiddenServiceIds is a SIBLING key: a hidden service still appears in
    // services[] and the consumer filters. Post-cutover the hidden list has no
    // content.* home (there is no is_active there) — it has to ride on the
    // blob, which is where FreshaSelectionResource already reads it from.
    expect($composed['hiddenServiceIds'])->toBe(['s:2']);
    expect(array_column($composed['services'], 'serviceId'))->toBe(['s:1', 's:2']);
});
