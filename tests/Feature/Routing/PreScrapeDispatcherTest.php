<?php

use App\Jobs\Platforms\FreshaListingCandidatesJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Routing\IriCanonicalizer;
use App\Routing\Placement;
use App\Routing\RoutingContext;
use App\Routing\SourceReconciler;
use App\Routing\Verdict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// A.4: a sign-up build's freshly proposed AUTO-band suggestion with a
// connector pre-scrapes — hidden connection + ingest provisioning — while
// the suggest band and the kill switch leave the proposal untouched.

beforeEach(function () {
    setupUsersTable();
    setupPreAccountBuildsTable();
    setupSitesTable();
    setupRoutingTables();
    setupContentTables();
    setupIngestTables();
});

function preScrapeSignupUser(bool $outreach = false): User
{
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'prescrape-'.substr($user->id, 0, 8), 'is_published' => false]);

    // The build row is what decides whether we may SPEND on this user
    // (isSelfServeSignup). Every real unclaimed user has one; the fixture used
    // to omit it, which is why the outreach case went unnoticed.
    $build = new PreAccountBuild([
        'source_type' => 'instagram',
        'source_ref' => 'PreScrape'.substr($user->id, 0, 6),
        'source_ref_lc' => 'prescrape'.substr($user->id, 0, 6),
        'built_via' => $outreach ? PreAccountBuild::VIA_STAFF : PreAccountBuild::VIA_SIGNUP,
        'expires_at' => now()->addDays(30),
    ]);
    $build->user()->associate($user);
    $build->save();

    return $user;
}

function preScrapeReconcile(User $user, string $band): void
{
    $iri = app(IriCanonicalizer::class)->canonicalize('https://www.instagram.com/someone');
    app(SourceReconciler::class)->reconcile(
        new Placement(Verdict::Choose, 'instagram.profile', 'someone', 'below_threshold', 'held for setup review',
            confidence: $band === 'auto' ? 75 : 50, band: $band),
        RoutingContext::forUser($user, 'link_in_bio'),
        $iri,
    );
}

it('pre-scrapes an auto-band suggestion into a hidden connection with an ingest source', function () {
    Queue::fake();
    $user = preScrapeSignupUser();

    preScrapeReconcile($user, 'auto');

    $connection = IntegrationConnection::query()->where('user_id', $user->id)->first();
    expect($connection)->not->toBeNull()
        ->and($connection->visibility)->toBe('hidden')
        ->and(DB::table('routing.source_intents')->where('user_id', $user->id)->value('state'))->toBe('applied')
        ->and(DB::table('ingest.sources')->where('connection_id', $connection->id)->exists())->toBeTrue();
});

it('never spends on an outreach build, even at the auto band', function () {
    // The bug this closes: the gate was isSignupBuild(), which is true for ANY
    // unclaimed non-paste user — so a staff/ManyChat outreach build, which may
    // sit unclaimed for weeks with nobody to ask, bought the same Apify-billed
    // scrapes as a person seconds from the setup dialog. 15 of 32 connectors
    // are CostClass::Actor. The suggestion itself must survive untouched: this
    // is a spending gate, not a routing change.
    Queue::fake();
    $user = preScrapeSignupUser(outreach: true);

    preScrapeReconcile($user, 'auto');

    expect(IntegrationConnection::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('routing.source_intents')->where('user_id', $user->id)->value('state'))->toBe('proposed');
});

it('still spends on a genuine self-serve signup at the auto band', function () {
    // The complement — asserts the gate did not simply turn pre-scrape off.
    Queue::fake();
    $user = preScrapeSignupUser();

    preScrapeReconcile($user, 'auto');

    expect(IntegrationConnection::query()->where('user_id', $user->id)->exists())->toBeTrue();
});

it('leaves a suggest-band proposal untouched', function () {
    Queue::fake();
    $user = preScrapeSignupUser();

    preScrapeReconcile($user, 'suggest');

    expect(IntegrationConnection::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('routing.source_intents')->where('user_id', $user->id)->value('state'))->toBe('proposed');
});

it('does nothing when the kill switch is off', function () {
    Queue::fake();
    config(['partna.pre_account.pre_scrape_enabled' => false]);
    $user = preScrapeSignupUser();

    preScrapeReconcile($user, 'auto');

    expect(IntegrationConnection::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('routing.source_intents')->where('user_id', $user->id)->value('state'))->toBe('proposed');
});

it('does not pre-scrape for a claimed user', function () {
    Queue::fake();
    $pro = createTenant('prescrape-claimed');

    $iri = app(IriCanonicalizer::class)->canonicalize('https://www.instagram.com/someone');
    app(SourceReconciler::class)->reconcile(
        new Placement(Verdict::Choose, 'instagram.profile', 'someone', 'below_threshold', 'too close', confidence: 75, band: 'auto'),
        RoutingContext::forUser($pro, 'link_in_bio'),
        $iri,
    );

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->exists())->toBeFalse();
});

it('does not mint a second row when the account is already held, hidden or visible', function () {
    Queue::fake();
    $user = preScrapeSignupUser();

    preScrapeReconcile($user, 'auto');
    preScrapeReconcile($user, 'auto');

    expect(IntegrationConnection::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('dispatches the Fresha listing-candidates job at any band', function () {
    Queue::fake();
    $user = preScrapeSignupUser();

    $iri = app(IriCanonicalizer::class)->canonicalize('https://www.fresha.com/a/anseo-studio-v0v92jna');
    app(SourceReconciler::class)->reconcile(
        new Placement(Verdict::Choose, 'fresha.book', 'anseo-studio-v0v92jna', 'below_threshold', 'held for setup review',
            confidence: 50, band: 'suggest'),
        RoutingContext::forUser($user, 'link_in_bio'),
        $iri,
    );

    Queue::assertPushed(FreshaListingCandidatesJob::class, fn ($job) => $job->userId === (string) $user->id);
});

it('skips the Fresha venue read once a candidate already exists', function () {
    Queue::fake();
    $user = preScrapeSignupUser();
    DB::table('site.workplace_candidates')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'place_id' => 'ChIJx',
        'name' => 'Anseo Studio', 'source' => 'fresha', 'corroboration' => '["name"]',
        'state' => 'proposed', 'created_at' => now(),
    ]);

    $iri = app(IriCanonicalizer::class)->canonicalize('https://www.fresha.com/a/anseo-studio-v0v92jna');
    app(SourceReconciler::class)->reconcile(
        new Placement(Verdict::Choose, 'fresha.book', 'anseo-studio-v0v92jna', 'below_threshold', 'held for setup review',
            confidence: 50, band: 'suggest'),
        RoutingContext::forUser($user, 'link_in_bio'),
        $iri,
    );

    Queue::assertNotPushed(FreshaListingCandidatesJob::class);
});
