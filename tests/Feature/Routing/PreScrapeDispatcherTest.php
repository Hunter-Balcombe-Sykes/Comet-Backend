<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Routing\IriCanonicalizer;
use App\Routing\Placement;
use App\Routing\RoutingContext;
use App\Routing\SourceReconciler;
use App\Routing\Verdict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// A.4: a sign-up build's freshly proposed AUTO-band suggestion with a
// connector pre-scrapes — hidden connection + ingest provisioning — while
// the suggest band and the kill switch leave the proposal untouched.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    setupContentTables();
    setupIngestTables();
});

function preScrapeSignupUser(): User
{
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'prescrape-'.substr($user->id, 0, 8), 'is_published' => false]);

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
