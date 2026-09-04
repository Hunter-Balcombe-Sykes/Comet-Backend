<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Routing\IriCanonicalizer;
use App\Routing\LinkProjector;
use App\Routing\Placement;
use App\Routing\PlacementPolicy;
use App\Routing\Projection;
use App\Routing\PublicSuffixList;
use App\Routing\RoutingContext;
use App\Routing\Rulepack;
use App\Routing\SourceReconciler;
use App\Routing\Verdict;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

// A.2 (setup-dialog run): a sign-up build connects NOTHING by itself. Every
// match becomes a banded Choose the setup dialog renders; the shop arm
// delegates to the commerce probe suggest-only. Since 2026-09-03 (owner:
// "nothing a harvester found ever auto-connects") the claimed-user harvest path
// is the same — only isConfirmedByUser() (paste, or the suggestions-inbox
// accept lane's confirmed flag) still reaches Place.
//
// Rewritten 2026-09-03 with the confidence system. The bands are no longer
// score ranges — there is no score. `auto` now means the matched rule CAPTURED
// an identifier, so the account can be named and the dialog pre-ticks the row;
// `suggest` means a shape matched but named nobody. The two arms that existed
// only to walk a projection across a threshold (45, and the signup floor 15
// below it) are deleted with the thresholds; what replaced them — a match that
// names only the brand becoming a Note — is asserted below against a REAL URL,
// because that outcome is now a property of the catalog rather than of a number
// a test could hand-pick.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
});

function signupUser(): User
{
    return User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);
}

/** instagram.profile is social — no capability gate, so band is the only variable. */
function socialProjection(?string $identifier = 'someone', bool $contested = false): Projection
{
    return new Projection('instagram.profile', 'instagram.profile.path', [], $identifier, null, $contested);
}

function spPlacementFor(string $url): Projection
{
    static $projector = null;
    static $canonicalizer = null;

    $projector ??= new LinkProjector(Rulepack::fromCompiledCatalog());
    $canonicalizer ??= new IriCanonicalizer(PublicSuffixList::instance());

    return $projector->project($canonicalizer->canonicalize($url));
}

it('turns a harvest that names an account into Choose(auto), never Place', function () {
    $placement = app(PlacementPolicy::class)->decide(
        socialProjection('someone'),
        RoutingContext::forUser(signupUser(), 'link_in_bio'),
    );

    expect($placement->verdict)->toBe(Verdict::Choose)
        ->and($placement->band)->toBe('auto');
});

it('turns a harvest that named nobody into Choose(suggest)', function () {
    $placement = app(PlacementPolicy::class)->decide(
        socialProjection(null),
        RoutingContext::forUser(signupUser(), 'bio_harvest'),
    );

    expect($placement->verdict)->toBe(Verdict::Choose)
        ->and($placement->band)->toBe('suggest');
});

it('demotes a contested harvest to suggest even when it captured an identifier', function () {
    $placement = app(PlacementPolicy::class)->decide(
        socialProjection('someone', contested: true),
        RoutingContext::forUser(signupUser(), 'link_in_bio'),
    );

    expect($placement->verdict)->toBe(Verdict::Choose)
        ->and($placement->band)->toBe('suggest');
});

it('keeps a link that matched only the brand as a Note (Gate 3, the floor\'s replacement)', function () {
    // A REAL host-only URL, not a hand-picked number: github.com/features/actions
    // matches github.profile's bare-host rule and nothing else, so it names the
    // brand and no account. Note keeps the link and drops only the false claim
    // that we know whose it is.
    $placement = app(PlacementPolicy::class)->decide(
        spPlacementFor('https://github.com/features/actions'),
        RoutingContext::forUser(signupUser(), 'link_in_bio'),
    );

    expect($placement->verdict)->toBe(Verdict::Note)
        ->and($placement->blockReason)->toBe('invalid_identifier')
        ->and($placement->identifier)->toBeNull()
        ->and($placement->band)->toBeNull()
        ->and(Verdict::Note->writesIntent())->toBeFalse();
});

it('keeps the paste path unchanged even for an unclaimed user', function () {
    $placement = app(PlacementPolicy::class)->decide(
        socialProjection('someone'),
        RoutingContext::forUser(signupUser(), 'paste'),
    );

    expect($placement->verdict)->toBe(Verdict::Place)
        ->and($placement->band)->toBe('auto');
});

it('no longer auto-connects a claimed-user harvest either (owner, 2026-09-03: nothing a harvester found auto-connects)', function () {
    $pro = createTenant('signup-policy-claimed');

    $placement = app(PlacementPolicy::class)->decide(
        socialProjection('someone'),
        RoutingContext::forUser($pro, 'link_in_bio'),
    );

    expect($placement->verdict)->toBe(Verdict::Choose)
        ->and($placement->band)->toBe('auto');
});

it('proposes the st-ali-bali OpenTable link instead of dropping it (Issue 3, end to end)', function () {
    // The live regression, at the layer that actually decided it. This URL
    // projected correctly and was then discarded as confidence 59 / margin 0
    // against a suggest threshold of 55 with a 10-point harvest penalty. It
    // must now reach the setup dialog as a pre-ticked row — Choose, banded
    // auto, carrying the restaurant id — and Choose writes an intent.
    $pro = createTenant('signup-policy-opentable');

    $placement = app(PlacementPolicy::class)->decide(
        spPlacementFor('https://www.opentable.com.au/booking/experiences-availability?rid=291533&restref=291533&experienceId=782864'),
        RoutingContext::forUser($pro, 'website_import'),
    );

    expect($placement->verdict)->toBe(Verdict::Choose)
        ->and($placement->surfaceKey)->toBe('opentable.reserve')
        ->and($placement->identifier)->toBe('291533')
        ->and($placement->band)->toBe('auto')
        ->and($placement->verdict->writesIntent())->toBeTrue();
});

it('delegates a sign-up build store root to the commerce probe suggest-only', function () {
    Bus::fake([CommerceProbeJob::class]);
    $user = signupUser();
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'signup-shop', 'is_published' => false]);

    $iri = app(IriCanonicalizer::class)->canonicalize('https://signupmerch.myshopify.com/');
    $out = app(SourceReconciler::class)->reconcile(
        new Placement(Verdict::Choose, 'shopify.store', 'signupmerch', null, 'held for setup review', band: 'auto'),
        RoutingContext::forUser($user, 'link_in_bio'),
        $iri,
    );

    Bus::assertDispatched(CommerceProbeJob::class, fn ($job) => $job->suggestOnly === true && $job->userId === (string) $user->id);
    expect($out['connection_id'])->toBeNull()
        ->and(DB::table('routing.source_intents')->where('user_id', $user->id)->count())->toBe(0);
});

it('keeps the claimed-user shop delegation suggest-only too — same as the sign-up lane now', function () {
    // SourceReconciler's shop arm ("Always suggest-only now") no longer
    // distinguishes claimed from unclaimed: any indirect, non-commerce_probe
    // origin gets suggestOnly:true regardless of the Placement it was handed
    // (this test hands it a bare Verdict::Place directly, as the accept lane
    // does; the arm still catches it — origin, not verdict, decides).
    Bus::fake([CommerceProbeJob::class]);
    $pro = createTenant('signup-shop-claimed');

    $iri = app(IriCanonicalizer::class)->canonicalize('https://claimedmerch.myshopify.com/');
    app(SourceReconciler::class)->reconcile(
        new Placement(Verdict::Place, 'shopify.store', 'claimedmerch'),
        RoutingContext::forUser($pro, 'link_in_bio'),
        $iri,
    );

    Bus::assertDispatched(CommerceProbeJob::class, fn ($job) => $job->suggestOnly === true);
});
