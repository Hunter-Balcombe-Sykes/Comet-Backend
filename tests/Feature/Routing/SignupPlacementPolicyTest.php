<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Routing\IriCanonicalizer;
use App\Routing\Placement;
use App\Routing\PlacementPolicy;
use App\Routing\Projection;
use App\Routing\RoutingContext;
use App\Routing\SourceReconciler;
use App\Routing\Verdict;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

// A.2 (setup-dialog run): a sign-up build connects NOTHING by itself. Every
// above-floor harvest becomes a banded Choose the setup dialog renders; the
// floor sits 15 below the class's suggest threshold; the shop arm delegates
// to the commerce probe suggest-only. Paste and claimed-user paths unchanged.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
});

function signupUser(): User
{
    return User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);
}

/** instagram.profile is social: auto 70, suggest 45, floor 30; indirect −10. */
function socialProjection(int $confidence, int $margin = 30): Projection
{
    return new Projection('instagram.profile', 'instagram.profile.path', [], $confidence, $margin, 'someone', null);
}

it('turns a harvest that clears the auto band into Choose(auto), never Place', function () {
    $placement = app(PlacementPolicy::class)->decide(
        socialProjection(85), // adjusted 75 ≥ auto 70
        RoutingContext::forUser(signupUser(), 'link_in_bio'),
    );

    expect($placement->verdict)->toBe(Verdict::Choose)
        ->and($placement->band)->toBe('auto')
        ->and($placement->confidence)->toBe(75);
});

it('turns a harvest in the suggest band into Choose(suggest)', function () {
    $placement = app(PlacementPolicy::class)->decide(
        socialProjection(60), // adjusted 50: ≥ suggest 45, < auto 70
        RoutingContext::forUser(signupUser(), 'link_in_bio'),
    );

    expect($placement->verdict)->toBe(Verdict::Choose)
        ->and($placement->band)->toBe('suggest');
});

it('still asks between the signup floor and the normal suggest threshold', function () {
    $placement = app(PlacementPolicy::class)->decide(
        socialProjection(42), // adjusted 32: < suggest 45, ≥ floor 30
        RoutingContext::forUser(signupUser(), 'bio_harvest'),
    );

    expect($placement->verdict)->toBe(Verdict::Choose)
        ->and($placement->band)->toBe('suggest');
});

it('keeps a link below the signup floor as a Note', function () {
    $placement = app(PlacementPolicy::class)->decide(
        socialProjection(35), // adjusted 25 < floor 30
        RoutingContext::forUser(signupUser(), 'link_in_bio'),
    );

    expect($placement->verdict)->toBe(Verdict::Note)
        ->and($placement->band)->toBeNull();
});

it('keeps the paste path unchanged even for an unclaimed user', function () {
    $placement = app(PlacementPolicy::class)->decide(
        socialProjection(85),
        RoutingContext::forUser(signupUser(), 'paste'),
    );

    expect($placement->verdict)->toBe(Verdict::Place)
        ->and($placement->band)->toBe('auto');
});

it('keeps the claimed-user harvest path unchanged', function () {
    $pro = createTenant('signup-policy-claimed');

    $placement = app(PlacementPolicy::class)->decide(
        socialProjection(85),
        RoutingContext::forUser($pro, 'link_in_bio'),
    );

    expect($placement->verdict)->toBe(Verdict::Place)
        ->and($placement->band)->toBe('auto');
});

it('delegates a sign-up build store root to the commerce probe suggest-only', function () {
    Bus::fake([CommerceProbeJob::class]);
    $user = signupUser();
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'signup-shop', 'is_published' => false]);

    $iri = app(IriCanonicalizer::class)->canonicalize('https://signupmerch.myshopify.com/');
    $out = app(SourceReconciler::class)->reconcile(
        new Placement(Verdict::Choose, 'shopify.store', 'signupmerch', 'below_threshold', 'held for setup review', confidence: 70, band: 'auto'),
        RoutingContext::forUser($user, 'link_in_bio'),
        $iri,
    );

    Bus::assertDispatched(CommerceProbeJob::class, fn ($job) => $job->suggestOnly === true && $job->userId === (string) $user->id);
    expect($out['connection_id'])->toBeNull()
        ->and(DB::table('routing.source_intents')->where('user_id', $user->id)->count())->toBe(0);
});

it('keeps the claimed-user shop delegation full-lane (not suggest-only)', function () {
    Bus::fake([CommerceProbeJob::class]);
    $pro = createTenant('signup-shop-claimed');

    $iri = app(IriCanonicalizer::class)->canonicalize('https://claimedmerch.myshopify.com/');
    app(SourceReconciler::class)->reconcile(
        new Placement(Verdict::Place, 'shopify.store', 'claimedmerch'),
        RoutingContext::forUser($pro, 'link_in_bio'),
        $iri,
    );

    Bus::assertDispatched(CommerceProbeJob::class, fn ($job) => $job->suggestOnly === false);
});
