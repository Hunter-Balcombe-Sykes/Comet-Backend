<?php

// #R4 (docs/reviews/2026-08-18-instagram-build-wave-RESULTS-RUN2.md). Most
// people link their own Instagram from their Linktree. The build's own
// connection stores resource_id = 'instagram' — the legacy singleton marker
// ManagesIntegrationConnection::defaultResourceId() writes — while the
// harvested one stores the real handle, so applyIntent()'s exact-match lookup
// saw two accounts and made a second connection, a second ingest.sources row
// and a second recurring sync job for ONE Instagram account.
//
// The pin that matters most is the SECOND test, not the first: a resolver that
// treated "row carries the legacy marker" as "same account" would merge two
// genuinely different YouTube channels. Both live on dev today
// (acct-77bc9a9984e6786c = casey, resource_id 'youtube' = @dvlpmnttv), which is
// how we know the over-merge is a real shape and not a hypothetical.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Routing\LinkRoutingService;
use App\Routing\RoutingContext;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    setupContentTables();
});

/**
 * A connection in the LEGACY SINGLETON scheme: resource_id is the platform
 * slug, not the account's identity. The real identity is recoverable only
 * from the payload — which is the whole difficulty.
 */
function seedMarkerConnection(User $user, string $surfaceKey, string $routingClass, string $slug, array $payload): IntegrationConnection
{
    $connection = new IntegrationConnection([
        'surface_key' => $surfaceKey,
        'routing_class' => $routingClass,
        'resource_id' => $slug,
        'payload' => $payload,
        'is_active' => true,
    ]);
    $connection->user_id = $user->id;
    $connection->save();

    return $connection;
}

function liveConnections(User $user, string $surfaceKey)
{
    return IntegrationConnection::query()
        ->where('user_id', $user->id)
        ->where('surface_key', $surfaceKey)
        ->whereNull('deleted_at')
        ->get();
}

it('folds a self-referential bio link into the build\'s own marker connection', function () {
    $pro = createTenant('r4-self-link');
    $marker = seedMarkerConnection($pro, 'instagram.profile', 'social', 'instagram', [
        'url' => 'https://instagram.com/crucibletattooco',
        'username' => 'crucibletattooco',
    ]);

    // The exact shape the bio page carries — Instagram's own share URL, with
    // the igsh tracking param the canonicalizer strips.
    $out = app(LinkRoutingService::class)->route(
        'https://www.instagram.com/crucibletattooco?igsh=MTdhM3NpeXg5ZHV6dw==',
        RoutingContext::forUser($pro, 'bio_harvest'),
    );

    expect($out['verdict'])->toBe('place');
    // Reused, not recreated: the SAME row the build already owns.
    expect($out['connectionId'])->toBe((string) $marker->id);
    expect(liveConnections($pro, 'instagram.profile'))->toHaveCount(1);
});

it('keeps two genuinely different channels apart when one wears the legacy marker', function () {
    $pro = createTenant('r4-no-over-merge');
    seedMarkerConnection($pro, 'youtube.channel', 'content', 'youtube', [
        'url' => 'https://www.youtube.com/@dvlpmnttv',
        // Live dev rows carry username = '' on this scheme — the identity is
        // ONLY in the url, so a resolver keying on username alone would fall
        // back to "no identifier" and (correctly) refuse to match, while one
        // keying on the marker alone would merge these two.
        'username' => '',
    ]);

    $out = app(LinkRoutingService::class)->route(
        'https://www.youtube.com/@casey',
        RoutingContext::forUser($pro, 'bio_harvest'),
    );

    expect($out['verdict'])->toBe('place');
    expect(liveConnections($pro, 'youtube.channel'))->toHaveCount(2);
});

it('holds a distinct handle behind an unresolvable marker as a swap, never a merge', function () {
    $pro = createTenant('r4-fail-open');
    $marker = seedMarkerConnection($pro, 'instagram.profile', 'social', 'instagram', [
        // No url, no username — a pending placeholder exactly as
        // InstagramSourceGenerator writes it before the scrape lands.
    ]);

    $out = app(LinkRoutingService::class)->route(
        'https://www.instagram.com/crucibletattooco',
        RoutingContext::forUser($pro, 'bio_harvest'),
    );

    // Never fail-MERGED: an unresolvable incumbent must not swallow a link
    // whose identity we can read perfectly well. Under FI-1 (2026-08-20,
    // socials are single-account) the open door is no longer a second
    // connection — it is the cap Hold, surfaced as a Swap against the
    // incumbent, with the marker row untouched. (This expected 'place' + a
    // second row when instagram.profile was multiAccount(5).)
    expect($out['verdict'])->toBe('hold');
    $intent = DB::table('routing.source_intents')->where('user_id', $pro->id)->first();
    expect(liveConnections($pro, 'instagram.profile'))->toHaveCount(1)
        ->and($intent->block_reason)->toBe('cap_reached')
        ->and($intent->conflicting_connection_id)->toBe((string) $marker->id);
});

it('does not let a marker row that IS this identity consume a cap slot', function () {
    $pro = createTenant('r4-cap');
    // Four unrelated accounts (over-cap legacy data now that FI-1 made
    // instagram.profile single-account) plus the marker row for OUR identity.
    // Re-routing our own link must fold into the marker — recognising an
    // account we already hold adds no account, so the cap (whatever its size,
    // and however far over it the legacy rows sit) must not block the fold.
    $marker = seedMarkerConnection($pro, 'instagram.profile', 'social', 'instagram', [
        'url' => 'https://instagram.com/crucibletattooco',
        'username' => 'crucibletattooco',
    ]);
    foreach (['alpha', 'bravo', 'charlie', 'delta'] as $other) {
        seedMarkerConnection($pro, 'instagram.profile', 'social', $other, [
            'url' => 'https://instagram.com/'.$other,
            'username' => $other,
        ]);
    }

    $out = app(LinkRoutingService::class)->route(
        'https://www.instagram.com/crucibletattooco',
        RoutingContext::forUser($pro, 'bio_harvest'),
    );

    expect($out['verdict'])->toBe('place')
        ->and($out['blockReason'] ?? null)->not->toBe('cap_reached');
    expect($out['connectionId'])->toBe((string) $marker->id);
    expect(liveConnections($pro, 'instagram.profile'))->toHaveCount(5);
});

it('records the intent under the REAL identifier while pointing at the marker row', function () {
    $pro = createTenant('r4-intent-ledger');
    $marker = seedMarkerConnection($pro, 'instagram.profile', 'social', 'instagram', [
        'url' => 'https://instagram.com/crucibletattooco',
        'username' => 'crucibletattooco',
    ]);

    app(LinkRoutingService::class)->route(
        'https://www.instagram.com/crucibletattooco',
        RoutingContext::forUser($pro, 'bio_harvest'),
    );

    // The ledger is the account of WHY a connection exists, so it keeps the
    // true identity (the handle), not the marker it happened to fold into.
    $intent = DB::table('routing.source_intents')
        ->where('user_id', $pro->id)
        ->where('surface_key', 'instagram.profile')
        ->first();

    expect($intent)->not->toBeNull()
        ->and($intent->identifier)->toBe('crucibletattooco')
        ->and($intent->state)->toBe('applied')
        ->and($intent->connection_id)->toBe((string) $marker->id);
});

it('is idempotent — a second scan of the same bio page adds nothing', function () {
    $pro = createTenant('r4-idempotent');
    $marker = seedMarkerConnection($pro, 'instagram.profile', 'social', 'instagram', [
        'url' => 'https://instagram.com/crucibletattooco',
        'username' => 'crucibletattooco',
    ]);

    foreach ([1, 2] as $_) {
        $out = app(LinkRoutingService::class)->route(
            'https://www.instagram.com/crucibletattooco',
            RoutingContext::forUser($pro, 'bio_harvest'),
        );
        expect($out['connectionId'])->toBe((string) $marker->id);
    }

    expect(liveConnections($pro, 'instagram.profile'))->toHaveCount(1)
        ->and(DB::table('routing.source_intents')->where('user_id', $pro->id)->count())->toBe(1);
});

it('matches a Fresha link carrying the ROTATED slug to the row healed by /team (canonical_key alias), and the OLD-slug link by resource_id', function () {
    // Slug rotation (2026-08-18): FreshaController::team() heals a rotated
    // venue by rewriting payload.url and stamping the current slug into
    // canonical_key, leaving resource_id on the slug the bio link carries.
    // Both link shapes must land on that one row — never a second Fresha
    // connection, never a Hold against itself.
    $pro = createTenant('r4-fresha-rot');
    $healed = new IntegrationConnection([
        'surface_key' => 'fresha.book',
        'routing_class' => 'booking',
        'resource_id' => 'anseo-studio-v0v92jna',
        'canonical_key' => 'anseo-studio-melbourne-140a-chapel-street-w8ajp04r',
        'payload' => ['url' => 'https://www.fresha.com/a/anseo-studio-melbourne-140a-chapel-street-w8ajp04r', 'source' => 'link_in_bio'],
        'is_active' => true,
    ]);
    $healed->user_id = $pro->id;
    $healed->save();

    $new = app(LinkRoutingService::class)->route(
        'https://www.fresha.com/a/anseo-studio-melbourne-140a-chapel-street-w8ajp04r/booking?menu=true&pId=2835260',
        RoutingContext::forUser($pro, 'bio_harvest'),
    );
    $old = app(LinkRoutingService::class)->route(
        'https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer?share=true&pId=2835260',
        RoutingContext::forUser($pro, 'bio_harvest'),
    );

    expect($new['verdict'])->toBe('place')->and($new['connectionId'])->toBe((string) $healed->id);
    expect($old['verdict'])->toBe('place')->and($old['connectionId'])->toBe((string) $healed->id);
    expect(liveConnections($pro, 'fresha.book'))->toHaveCount(1);
});

it('M-7: matches a handle-surface identifier case-insensitively, keeps opaque ids exact', function () {
    // thejunglegiants live: youtube.com/@TheJungleGiants vs /@thejunglegiants
    // are one channel — the case-sensitive compare re-proposed the user's own
    // connected channel as a suggestion.
    $pro = createTenant('m7-handles');

    $yt = new \App\Models\Core\Site\IntegrationConnection([
        'surface_key' => 'youtube.channel', 'routing_class' => 'content',
        'resource_id' => 'thejunglegiants', 'payload' => ['url' => 'https://www.youtube.com/@thejunglegiants'],
        'is_active' => true,
    ]);
    $yt->user_id = $pro->id;
    $yt->save();

    $identity = app(\App\Routing\ConnectionIdentity::class);
    expect($identity->matchExisting($pro, 'youtube.channel', 'TheJungleGiants'))->toBe((string) $yt->id)
        ->and($identity->matchExisting($pro, 'youtube.channel', 'someoneelse'))->toBeNull();

    // Opaque numeric-id surface keeps the exact compare.
    $ot = new \App\Models\Core\Site\IntegrationConnection([
        'surface_key' => 'opentable.reserve', 'routing_class' => 'reservations',
        'resource_id' => '49820', 'payload' => ['url' => 'https://www.opentable.com/restaurant/profile/49820'],
        'is_active' => true,
    ]);
    $ot->user_id = $pro->id;
    $ot->save();
    expect($identity->matchExisting($pro, 'opentable.reserve', '49820'))->toBe((string) $ot->id);
});

it('M-8: a Choose-band alias of an already-connected channel folds instead of proposing the user their own account', function () {
    // thejunglegiants live: linktree carried @thejunglegiants (connected),
    // the website carried @TheJungleGiants — same channel, and the Choose
    // band skipped the alias lookup, filing the user's OWN channel as a
    // suggestion in the inbox. Driven through reconcile() with an explicit
    // Choose placement (critic catch on the first version: the URL scores 75
    // ≥ the content auto threshold, so routing it end-to-end lands on Place
    // and never exercises the Choose arm at all).
    $pro = createTenant('m8-choose-fold');
    $existing = seedMarkerConnection($pro, 'youtube.channel', 'content', 'thejunglegiants', [
        'url' => 'https://www.youtube.com/@thejunglegiants',
    ]);

    $iri = app(\App\Routing\IriCanonicalizer::class)->canonicalize('https://www.youtube.com/@TheJungleGiants');
    $placement = new \App\Routing\Placement(\App\Routing\Verdict::Choose, 'youtube.channel', 'TheJungleGiants');
    $out = app(\App\Routing\SourceReconciler::class)->reconcile(
        $placement,
        RoutingContext::forUser($pro, 'link_in_bio'),
        $iri,
    );

    // The aliased Choose upgraded to Place and folded into the existing row —
    // one connection, no proposal.
    expect($out['verdict'])->toBe('place')
        ->and($out['connection_id'])->toBe((string) $existing->id)
        ->and(liveConnections($pro, 'youtube.channel'))->toHaveCount(1);

    $proposed = DB::table('routing.source_intents')
        ->where('user_id', $pro->id)
        ->where('surface_key', 'youtube.channel')
        ->where('state', 'proposed')
        ->count();
    expect($proposed)->toBe(0);
});
